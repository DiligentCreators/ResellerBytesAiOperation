<?php

namespace App\Services\Alerts;

use App\Events\CriticalFindingRaised;
use App\Events\FixActionAutoQueued;
use App\Events\FixActionFailed;
use App\Events\InstallationHeartbeatStateChanged;
use App\Models\DiagnosticFinding;
use App\Models\SafeActionExecution;
use App\Models\TenantAlertChannel;
use App\Models\TenantAlertDelivery;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\TenantEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class AlertDispatcher
{
    public function __construct(
        private readonly AlertChannelDriverFactory $drivers,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    public function handleHeartbeatStateChanged(InstallationHeartbeatStateChanged $event): void
    {
        if ($event->tenantId === null) {
            return;
        }

        $integration = WhmcsIntegration::query()->find($event->integrationId);
        $name = $integration !== null ? $integration->name : 'WHMCS install';
        $fixCenterUrl = $this->fixCenterUrl();

        if ($event->newState === WhmcsIntegration::HEARTBEAT_STATE_DOWN) {
            $this->dispatch(
                $event->tenantId,
                'installation.heartbeat.down',
                'heartbeat:down:'.$event->integrationId,
                new AlertMessage(
                    title: 'Installation heartbeat is down',
                    body: 'No heartbeat was received past the down threshold. The WHMCS install may be offline or the addon may have stopped reporting. Open Fix Center to investigate.',
                    installationName: $name,
                    fixCenterUrl: $fixCenterUrl,
                ),
            );

            return;
        }

        if ($event->newState === WhmcsIntegration::HEARTBEAT_STATE_OK
            && in_array($event->previousState, [
                WhmcsIntegration::HEARTBEAT_STATE_DOWN,
                WhmcsIntegration::HEARTBEAT_STATE_STALE,
            ], true)) {
            $this->dispatch(
                $event->tenantId,
                'installation.heartbeat.recovery',
                'heartbeat:recovery:'.$event->integrationId.':'.$event->changedAt->getTimestamp(),
                new AlertMessage(
                    title: 'Installation back online',
                    body: 'Heartbeats have resumed. The install is reporting again.',
                    installationName: $name,
                    fixCenterUrl: $fixCenterUrl,
                ),
            );
        }
    }

    public function handleCriticalFindingRaised(CriticalFindingRaised $event): void
    {
        $finding = DiagnosticFinding::query()->with('diagnosticRun.integration')->find($event->findingId);
        if ($finding === null || $finding->tenant_id === null) {
            return;
        }

        $integrationName = $finding->diagnosticRun?->integration?->name;
        $title = is_string($finding->title) && $finding->title !== ''
            ? $finding->title
            : 'Critical finding detected';

        $integrationId = $finding->diagnosticRun !== null
            ? $finding->diagnosticRun->integration_id
            : 'unknown';

        $this->dispatch(
            $finding->tenant_id,
            'finding.critical',
            'finding.critical:'.$integrationId.':'.$finding->fingerprint,
            new AlertMessage(
                title: 'Critical finding: '.$title,
                body: is_string($finding->summary) && $finding->summary !== ''
                    ? $finding->summary
                    : 'A critical WHMCS issue was detected. Open Fix Center to review and resolve it.',
                installationName: $integrationName,
                fixCenterUrl: $this->fixCenterUrl(),
            ),
        );
    }

    public function handleFixActionFailed(FixActionFailed $event): void
    {
        $execution = SafeActionExecution::query()->with('integration')->find($event->executionId);
        if ($execution === null || $execution->tenant_id === null) {
            return;
        }

        $actionKey = is_string($execution->action_key) ? $execution->action_key : 'fix';

        $this->dispatch(
            $execution->tenant_id,
            'fix.failed',
            'fix.failed:'.$execution->id,
            new AlertMessage(
                title: 'Fix failed',
                body: 'A one-click or guided fix ('.$actionKey.') did not complete successfully. Open Fix Center to retry or follow the guided steps.',
                installationName: $execution->integration?->name,
                fixCenterUrl: $this->fixCenterUrl(),
            ),
        );
    }

    public function handleFixActionAutoQueued(FixActionAutoQueued $event): void
    {
        $execution = SafeActionExecution::query()->with('integration')->find($event->executionId);
        if ($execution === null || $execution->tenant_id === null) {
            return;
        }

        $actionKey = is_string($execution->action_key) ? $execution->action_key : 'fix';

        $this->dispatch(
            $execution->tenant_id,
            'fix.auto_queued',
            'fix.auto_queued:'.$execution->id,
            new AlertMessage(
                title: 'Auto-fix queued',
                body: 'An automatic fix ('.$actionKey.') was queued for the WHMCS addon on the next heartbeat. Review it in Fix Center / Fix Log.',
                installationName: $execution->integration?->name,
                fixCenterUrl: $this->fixCenterUrl(),
            ),
        );
    }

    /**
     * Send a one-off test alert to a single channel (bypasses event subscription, still entitles).
     */
    public function sendTest(TenantAlertChannel $channel): bool
    {
        try {
            $this->entitlements->requireFeature($channel->tenant_id, 'alerts.channels');
        } catch (AuthorizationException) {
            return false;
        }

        $message = new AlertMessage(
            title: 'Test alert',
            body: 'This is a test alert from ResellerBytes AI Ops. Your alert channel is working.',
            fixCenterUrl: $this->fixCenterUrl(),
        );

        return $this->deliverToChannel(
            $channel,
            'alert.test',
            'alert.test:'.$channel->id.':'.now('UTC')->format('YmdHis'),
            $message,
            skipDedupe: true,
        );
    }

    /**
     * Fan out an alert to all enabled channels for the tenant that subscribe to the key.
     */
    public function dispatch(string $tenantId, string $alertKey, string $dedupeKey, AlertMessage $message): void
    {
        try {
            $this->entitlements->requireFeature($tenantId, 'alerts.channels');
        } catch (AuthorizationException) {
            return;
        } catch (Throwable $exception) {
            Log::warning('Workspace alert entitlement check failed.', [
                'tenant_id' => $tenantId,
                'alert_key' => $alertKey,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        $channels = TenantAlertChannel::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        foreach ($channels as $channel) {
            if (! $channel->listensFor($alertKey)) {
                continue;
            }

            try {
                $this->deliverToChannel($channel, $alertKey, $dedupeKey, $message);
            } catch (Throwable $exception) {
                Log::warning('Workspace alert channel delivery crashed.', [
                    'tenant_id' => $tenantId,
                    'channel_id' => $channel->id,
                    'alert_key' => $alertKey,
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }

    private function deliverToChannel(
        TenantAlertChannel $channel,
        string $alertKey,
        string $dedupeKey,
        AlertMessage $message,
        bool $skipDedupe = false,
    ): bool {
        $repeatMinutes = max(1, (int) config('alerts.repeat_minutes', 60));

        if (! $skipDedupe && $this->wasRecentlySent($channel, $dedupeKey, $repeatMinutes)) {
            TenantAlertDelivery::query()->create([
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'alert_key' => $alertKey,
                'dedupe_key' => $dedupeKey,
                'status' => TenantAlertDelivery::STATUS_SUPPRESSED,
                'error' => null,
                'sent_at' => null,
            ]);

            return false;
        }

        try {
            $this->drivers->make($channel->type)->send($channel, $alertKey, $message);

            TenantAlertDelivery::query()->create([
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'alert_key' => $alertKey,
                'dedupe_key' => $dedupeKey,
                'status' => TenantAlertDelivery::STATUS_SENT,
                'error' => null,
                'sent_at' => now('UTC'),
            ]);

            $channel->forceFill([
                'last_error' => null,
                'last_sent_at' => now('UTC'),
                'verified_at' => $channel->verified_at ?? now('UTC'),
            ])->save();

            return true;
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 255);

            TenantAlertDelivery::query()->create([
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'alert_key' => $alertKey,
                'dedupe_key' => $dedupeKey,
                'status' => TenantAlertDelivery::STATUS_FAILED,
                'error' => $error,
                'sent_at' => null,
            ]);

            $channel->forceFill([
                'last_error' => $error,
            ])->save();

            return false;
        }
    }

    private function wasRecentlySent(TenantAlertChannel $channel, string $dedupeKey, int $repeatMinutes): bool
    {
        return TenantAlertDelivery::query()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', TenantAlertDelivery::STATUS_SENT)
            ->where('sent_at', '>=', now('UTC')->subMinutes($repeatMinutes))
            ->exists();
    }

    private function fixCenterUrl(): string
    {
        try {
            return URL::route('fix-center.index');
        } catch (Throwable) {
            return '/fix-center';
        }
    }
}

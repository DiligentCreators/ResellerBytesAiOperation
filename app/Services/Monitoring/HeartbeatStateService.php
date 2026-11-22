<?php

namespace App\Services\Monitoring;

use App\Events\InstallationHeartbeatStateChanged;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRecommendation;
use App\Models\DiagnosticRun;
use App\Models\TenantMembership;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\PlatformSettings;
use App\Services\Tenancy\TenantNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class HeartbeatStateService
{
    public const STATE_UNKNOWN = 'unknown';

    public const STATE_OK = 'ok';

    public const STATE_STALE = 'stale';

    public const STATE_DOWN = 'down';

    public const STATES = [
        self::STATE_UNKNOWN,
        self::STATE_OK,
        self::STATE_STALE,
        self::STATE_DOWN,
    ];

    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly FindingContinuityService $continuity,
        private readonly TenantNotificationService $notifications,
    ) {}

    /**
     * Mark an integration as healthy after a signed heartbeat is accepted.
     */
    public function markOk(WhmcsIntegration $integration): void
    {
        $this->transition($integration, self::STATE_OK, now('UTC'));
    }

    /**
     * Evaluate stale/down thresholds for integrations that have ever heartbeated.
     *
     * @return array{checked: int, changed: int}
     */
    public function checkMissedHeartbeats(): array
    {
        $staleMinutes = $this->intSetting('monitoring.heartbeat_stale_minutes', 30);
        $downMinutes = $staleMinutes * 3;
        $now = now('UTC');
        $checked = 0;
        $changed = 0;

        WhmcsIntegration::query()
            ->where('status', 'active')
            ->whereNotNull('last_heartbeat_at')
            ->orderBy('id')
            ->chunkById(100, function ($integrations) use ($staleMinutes, $downMinutes, $now, &$checked, &$changed): void {
                foreach ($integrations as $integration) {
                    $checked++;

                    try {
                        $last = Carbon::parse($integration->last_heartbeat_at)->utc();
                    } catch (Throwable) {
                        continue;
                    }

                    $ageMinutes = $last->diffInMinutes($now);
                    $desired = match (true) {
                        $ageMinutes > $downMinutes => self::STATE_DOWN,
                        $ageMinutes > $staleMinutes => self::STATE_STALE,
                        default => self::STATE_OK,
                    };

                    $previous = (string) ($integration->heartbeat_state ?? self::STATE_UNKNOWN);
                    if ($previous === $desired) {
                        continue;
                    }

                    $this->transition($integration, $desired, $now);
                    $changed++;
                }
            });

        return ['checked' => $checked, 'changed' => $changed];
    }

    /**
     * @param  'unknown'|'ok'|'stale'|'down'  $newState
     */
    public function transition(WhmcsIntegration $integration, string $newState, CarbonInterface $changedAt): void
    {
        if (! in_array($newState, self::STATES, true)) {
            return;
        }

        $previous = $this->normalizeState($integration->heartbeat_state ?? null);
        if ($previous === $newState) {
            return;
        }

        DB::transaction(function () use ($integration, $newState, $changedAt): void {
            $locked = WhmcsIntegration::query()->whereKey($integration->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $current = $this->normalizeState($locked->heartbeat_state ?? null);
            if ($current === $newState) {
                return;
            }

            $locked->forceFill([
                'heartbeat_state' => $newState,
                'heartbeat_state_changed_at' => $changedAt,
            ])->save();

            if (in_array($newState, [self::STATE_STALE, self::STATE_DOWN], true)) {
                $this->openOrRefreshMissedFinding($locked, $newState, $changedAt);
            }

            if ($newState === self::STATE_OK) {
                $this->closeMissedFindings($locked);
            }

            $this->notifyTenantAdmins($locked, $current, $newState, $changedAt);

            event(new InstallationHeartbeatStateChanged(
                $locked->id,
                $locked->tenant_id,
                $current,
                $newState,
                $changedAt,
            ));

            $integration->setRawAttributes($locked->getAttributes(), true);
            $integration->syncOriginal();
        });
    }

    /**
     * @return 'unknown'|'ok'|'stale'|'down'
     */
    private function normalizeState(mixed $state): string
    {
        if (is_string($state) && in_array($state, self::STATES, true)) {
            return $state;
        }

        return self::STATE_UNKNOWN;
    }

    private function openOrRefreshMissedFinding(WhmcsIntegration $integration, string $state, CarbonInterface $changedAt): void
    {
        $catalog = WhmcsIssueCatalog::get('heartbeat.missed');
        if ($catalog === null) {
            return;
        }

        $run = $this->resolveFindingRun($integration);

        $findingData = [
            'category' => (string) ($catalog['category'] ?? 'cron'),
            'title' => (string) ($catalog['title'] ?? 'Install heartbeat is overdue'),
            'summary' => (string) ($catalog['why_it_matters'] ?? $catalog['title'] ?? 'heartbeat.missed'),
            'evidence_paths' => [
                'integration.last_heartbeat_at',
                'integration.heartbeat_state',
            ],
            'severity' => (string) ($catalog['severity'] ?? 'critical'),
        ];

        $fingerprint = $this->continuity->fingerprintForIssueKey('heartbeat.missed', $findingData);
        $evidence = [
            'integration.last_heartbeat_at' => $integration->last_heartbeat_at?->toIso8601ZuluString(),
            'integration.heartbeat_state' => $state,
            'integration.heartbeat_state_changed_at' => $changedAt->toIso8601ZuluString(),
            'monitoring.heartbeat_stale_minutes' => $this->intSetting('monitoring.heartbeat_stale_minutes', 30),
        ];

        $existing = DiagnosticFinding::query()
            ->where('issue_key', 'heartbeat.missed')
            ->where('source', DiagnosticFinding::SOURCE_RULE)
            ->where('fingerprint', $fingerprint)
            ->whereHas('diagnosticRun', fn ($query) => $query->where('integration_id', $integration->id))
            ->active()
            ->latest('created_at')
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'evidence' => $evidence,
                'severity' => $findingData['severity'],
                'status_updated_at' => $changedAt,
            ])->save();

            return;
        }

        $finding = DiagnosticFinding::create([
            'diagnostic_run_id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'fingerprint' => $fingerprint,
            'issue_key' => 'heartbeat.missed',
            'source' => DiagnosticFinding::SOURCE_RULE,
            'affected_count' => 1,
            'fix_mode' => is_string($catalog['fix_mode'] ?? null) ? $catalog['fix_mode'] : 'guided',
            'category' => $findingData['category'],
            'severity' => $findingData['severity'],
            'title' => $findingData['title'],
            'summary' => $findingData['summary'],
            'evidence' => $evidence,
            'confidence' => 1,
            'status' => DiagnosticFinding::STATUS_OPEN,
            'status_updated_at' => $changedAt,
        ]);

        $steps = $catalog['guided_steps'] ?? [];
        if (is_array($steps)) {
            foreach (array_values($steps) as $step) {
                if (! is_string($step) || trim($step) === '') {
                    continue;
                }

                DiagnosticRecommendation::create([
                    'diagnostic_finding_id' => $finding->id,
                    'tenant_id' => $run->tenant_id,
                    'recommendation' => $step,
                    'rationale' => is_string($catalog['why_it_matters'] ?? null)
                        ? $catalog['why_it_matters']
                        : $step,
                    'risk_level' => is_string($catalog['risk'] ?? null) ? $catalog['risk'] : 'high',
                    'requires_manual_action' => true,
                ]);
            }
        }

        $this->continuity->inheritActiveWorkflow($finding);
    }

    private function closeMissedFindings(WhmcsIntegration $integration): void
    {
        $findings = DiagnosticFinding::query()
            ->where('issue_key', 'heartbeat.missed')
            ->where('source', DiagnosticFinding::SOURCE_RULE)
            ->active()
            ->whereHas('diagnosticRun', fn ($query) => $query->where('integration_id', $integration->id))
            ->get();

        foreach ($findings as $finding) {
            $finding->forceFill([
                'status' => DiagnosticFinding::STATUS_RESOLVED,
                'status_note' => 'Heartbeats resumed.',
                'status_updated_at' => now(),
                'resolved_at' => now(),
            ])->save();
        }
    }

    private function resolveFindingRun(WhmcsIntegration $integration): DiagnosticRun
    {
        $latest = DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->first();

        if ($latest !== null) {
            return $latest;
        }

        return DiagnosticRun::create([
            'integration_id' => $integration->id,
            'tenant_id' => $integration->tenant_id,
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => '1.1',
            'addon_version' => is_string($integration->addon_version) ? $integration->addon_version : '0.0.0',
            'status' => 'monitoring',
            'collected_at' => now('UTC'),
            'received_at' => now('UTC'),
            'payload' => [
                'collector' => [
                    'capabilities' => [],
                    'limitations' => ['synthetic_monitoring_run'],
                ],
            ],
            'payload_sha256' => hash('sha256', 'heartbeat-monitoring:'.$integration->id),
            'analysis_summary' => 'Synthetic monitoring run for heartbeat.missed findings.',
        ]);
    }

    /**
     * @param  'unknown'|'ok'|'stale'|'down'  $previous
     * @param  'unknown'|'ok'|'stale'|'down'  $newState
     */
    private function notifyTenantAdmins(
        WhmcsIntegration $integration,
        string $previous,
        string $newState,
        CarbonInterface $changedAt,
    ): void {
        if ($integration->tenant_id === null) {
            return;
        }

        $recipients = TenantMembership::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('role', [
                TenantMembership::ROLE_OWNER,
                TenantMembership::ROLE_ADMIN,
            ])
            ->pluck('user_id');

        $notificationKey = sprintf(
            'heartbeat:%s:%s:%s:%d',
            $integration->id,
            $previous,
            $newState,
            $changedAt->getTimestamp(),
        );

        foreach ($recipients as $userId) {
            try {
                $this->notifications->publish(
                    $integration->tenant_id,
                    (int) $userId,
                    'installation.heartbeat.state_changed',
                    [
                        'integration_id' => $integration->id,
                        'integration_name' => $integration->name,
                        'previous_state' => $previous,
                        'new_state' => $newState,
                        'changed_at' => $changedAt->toIso8601ZuluString(),
                    ],
                    $notificationKey,
                );
            } catch (Throwable) {
                // Notification failures must not roll back heartbeat state transitions.
            }
        }
    }

    private function intSetting(string $key, int $default): int
    {
        try {
            $value = $this->settings->get($key);
        } catch (Throwable) {
            $value = config($key, $default);
        }

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}

<?php

namespace App\Services\SafeActions;

use App\Events\FixActionAutoQueued;
use App\Models\AutoFixPolicy;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\PlatformSettings;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantNotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class AutoFixRunner
{
    public function __construct(
        private readonly SafeActionExecutor $executor,
        private readonly SafeActionRegistry $registry,
        private readonly FindingSafeActionMapper $mapper,
        private readonly PlatformSettings $settings,
        private readonly TenantEntitlementService $entitlements,
        private readonly TenantNotificationService $notifications,
        private readonly FindingContinuityService $continuity,
    ) {}

    /**
     * Queue allowlisted auto fixes for newly detected findings that match enabled policies.
     *
     * @param  list<DiagnosticFinding>  $findings
     * @return list<SafeActionExecution>
     */
    public function processFindings(DiagnosticRun $run, array $findings): array
    {
        if ($findings === [] || $run->tenant_id === null) {
            return [];
        }

        if (! $this->fixesEnabled()) {
            return [];
        }

        try {
            $this->entitlements->requireFeature($run->tenant_id, 'fixes.auto');
        } catch (AuthorizationException) {
            return [];
        } catch (Throwable $exception) {
            Log::warning('Auto-fix entitlement check failed.', [
                'tenant_id' => $run->tenant_id,
                'exception_class' => $exception::class,
            ]);

            return [];
        }

        $run->loadMissing('integration');
        $integration = $run->integration;
        if ($integration === null || $integration->tenant_id !== $run->tenant_id) {
            return [];
        }

        $queued = [];

        foreach ($findings as $finding) {
            if (! $finding instanceof DiagnosticFinding) {
                continue;
            }

            if ($finding->status !== DiagnosticFinding::STATUS_OPEN) {
                continue;
            }

            try {
                $execution = $this->processFinding($integration, $finding);
                if ($execution !== null) {
                    $queued[] = $execution;
                }
            } catch (Throwable $exception) {
                Log::warning('Auto-fix could not queue for finding.', [
                    'finding_id' => $finding->id,
                    'tenant_id' => $run->tenant_id,
                    'exception_class' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $queued;
    }

    private function processFinding(WhmcsIntegration $integration, DiagnosticFinding $finding): ?SafeActionExecution
    {
        if ($this->issueNeverAuto($finding)) {
            return null;
        }

        $actionKeys = $this->autoActionKeysForFinding($finding);
        if ($actionKeys === []) {
            return null;
        }

        foreach ($actionKeys as $actionKey) {
            if (! $this->registry->isAllowlisted($actionKey)) {
                continue;
            }

            $definition = $this->registry->get($actionKey);
            if (! ($definition['auto_allowed'] ?? false)) {
                continue;
            }

            $policy = $this->matchingPolicy($integration, $actionKey);
            if ($policy === null || ! $policy->enabled) {
                continue;
            }

            if ($this->hasActiveAutoExecution($finding, $actionKey)) {
                continue;
            }

            if ($this->platformDailyCapReached($integration->tenant_id)) {
                return null;
            }

            if ($this->policyDailyCapReached($policy)) {
                continue;
            }

            $creator = User::query()->find($policy->created_by_user_id);
            if ($creator === null) {
                continue;
            }

            try {
                $execution = $this->executor->request(
                    $integration,
                    $creator,
                    $actionKey,
                    [
                        'mode' => SafeActionExecution::MODE_AUTO,
                        'auto_fix_policy_id' => $policy->id,
                    ],
                    $finding,
                );
            } catch (InvalidArgumentException) {
                // No auto-safe targets remain (e.g. module_queue create/suspend/unsuspend/terminate).
                continue;
            }

            $execution = $this->executor->approve(
                $execution,
                $creator,
                'Auto-fix policy '.$policy->id,
            );

            $this->notifyQueued($execution, $policy);
            event(new FixActionAutoQueued($execution->id, (string) $execution->tenant_id, $policy->id));

            return $execution;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function autoActionKeysForFinding(DiagnosticFinding $finding): array
    {
        $suggested = $this->mapper->suggestedActions($finding);
        $keys = [];
        foreach ($suggested as $action) {
            if (($action['auto_allowed'] ?? false) === true) {
                $keys[] = $action['key'];
            }
        }

        return array_values(array_unique($keys));
    }

    private function matchingPolicy(WhmcsIntegration $integration, string $actionKey): ?AutoFixPolicy
    {
        $specific = AutoFixPolicy::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('integration_id', $integration->id)
            ->where('action_key', $actionKey)
            ->where('enabled', true)
            ->first();

        if ($specific !== null) {
            return $specific;
        }

        return AutoFixPolicy::query()
            ->where('tenant_id', $integration->tenant_id)
            ->whereNull('integration_id')
            ->where('action_key', $actionKey)
            ->where('enabled', true)
            ->first();
    }

    private function hasActiveAutoExecution(DiagnosticFinding $finding, string $actionKey): bool
    {
        $finding->loadMissing('diagnosticRun');
        $integrationId = $finding->diagnosticRun?->integration_id;
        $findingIds = $integrationId !== null
            ? $this->continuity->relatedFindingIds($finding, $integrationId)
            : [(string) $finding->id];

        if ($findingIds === []) {
            $findingIds = [(string) $finding->id];
        }

        return SafeActionExecution::query()
            ->whereIn('finding_id', $findingIds)
            ->where('action_key', $actionKey)
            ->where('mode', SafeActionExecution::MODE_AUTO)
            ->whereIn('status', [
                SafeActionExecution::STATUS_PENDING_APPROVAL,
                SafeActionExecution::STATUS_APPROVED,
                SafeActionExecution::STATUS_AWAITING_AGENT,
            ])
            ->exists();
    }

    private function platformDailyCapReached(string $tenantId): bool
    {
        $cap = $this->platformDailyCap();
        if ($cap < 1) {
            return true;
        }

        return $this->autoExecutionsToday($tenantId) >= $cap;
    }

    private function policyDailyCapReached(AutoFixPolicy $policy): bool
    {
        if ($policy->daily_cap === null) {
            return false;
        }

        if ($policy->daily_cap < 1) {
            return true;
        }

        $count = SafeActionExecution::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('mode', SafeActionExecution::MODE_AUTO)
            ->where('requested_at', '>=', Carbon::now('UTC')->startOfDay())
            ->where('input->auto_fix_policy_id', $policy->id)
            ->count();

        return $count >= $policy->daily_cap;
    }

    private function autoExecutionsToday(string $tenantId): int
    {
        return SafeActionExecution::query()
            ->where('tenant_id', $tenantId)
            ->where('mode', SafeActionExecution::MODE_AUTO)
            ->where('requested_at', '>=', Carbon::now('UTC')->startOfDay())
            ->count();
    }

    private function fixesEnabled(): bool
    {
        try {
            return (bool) $this->settings->get('fixes.enabled');
        } catch (Throwable) {
            return (bool) config('fixes.enabled', true);
        }
    }

    private function platformDailyCap(): int
    {
        try {
            $value = $this->settings->get('fixes.auto_fix_daily_cap');
        } catch (Throwable) {
            $value = config('fixes.auto_fix_daily_cap', 20);
        }

        return is_numeric($value) ? max(0, (int) $value) : 20;
    }

    private function issueNeverAuto(DiagnosticFinding $finding): bool
    {
        $issueKey = $finding->issue_key;
        if (! is_string($issueKey) || $issueKey === '') {
            return false;
        }

        $entry = WhmcsIssueCatalog::get($issueKey);

        return is_array($entry) && ($entry['never_auto'] ?? false) === true;
    }

    private function notifyQueued(SafeActionExecution $execution, AutoFixPolicy $policy): void
    {
        if ($execution->tenant_id === null) {
            return;
        }

        $recipients = TenantMembership::query()
            ->where('tenant_id', $execution->tenant_id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('role', [
                TenantMembership::ROLE_OWNER,
                TenantMembership::ROLE_ADMIN,
                TenantMembership::ROLE_OPERATOR,
            ])
            ->pluck('user_id');

        $notificationKey = 'fix-auto:'.$execution->id;

        foreach ($recipients as $userId) {
            try {
                $this->notifications->publish(
                    $execution->tenant_id,
                    (int) $userId,
                    'fix.auto_queued',
                    [
                        'execution_id' => $execution->id,
                        'action_key' => $execution->action_key,
                        'policy_id' => $policy->id,
                        'finding_id' => $execution->finding_id,
                    ],
                    $notificationKey,
                );
            } catch (Throwable) {
                // Notification failures must not undo the queued fix.
            }
        }
    }
}

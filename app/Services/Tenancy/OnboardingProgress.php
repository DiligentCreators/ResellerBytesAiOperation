<?php

namespace App\Services\Tenancy;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\WhmcsIntegration;

class OnboardingProgress
{
    /**
     * Derive onboarding step completion from real tenant state, persist
     * onboarding_completed_at when every step is done, and return checklist data.
     *
     * @return array{
     *     needed: bool,
     *     tenant_id: string,
     *     steps: array{workspace: bool, plan: bool, whmcs: bool, first_scan: bool},
     *     current_step: string,
     *     first_scan: array{ready: bool, issues_count: int, one_click_count: int}|null
     * }
     */
    public function forTenant(Tenant $tenant): array
    {
        if ($tenant->onboarding_completed_at !== null) {
            return [
                'needed' => false,
                'tenant_id' => (string) $tenant->id,
                'steps' => [
                    'workspace' => true,
                    'plan' => true,
                    'whmcs' => true,
                    'first_scan' => true,
                ],
                'current_step' => 'done',
                'first_scan' => null,
            ];
        }

        $steps = $this->deriveSteps($tenant);
        $this->persistDerivedFlags($tenant, $steps);
        $firstScan = $this->firstScanSummary($tenant);

        if ($steps['workspace'] && $steps['plan'] && $steps['whmcs'] && $steps['first_scan']) {
            $tenant->forceFill(['onboarding_completed_at' => now()])->save();

            return [
                'needed' => false,
                'tenant_id' => (string) $tenant->id,
                'steps' => $steps,
                'current_step' => 'done',
                'first_scan' => $firstScan,
            ];
        }

        return [
            'needed' => true,
            'tenant_id' => (string) $tenant->id,
            'steps' => $steps,
            'current_step' => $this->currentStep($steps),
            'first_scan' => $firstScan,
        ];
    }

    /**
     * @return array{workspace: bool, plan: bool, whmcs: bool, first_scan: bool}
     */
    public function deriveSteps(Tenant $tenant): array
    {
        $onboarding = (array) data_get($tenant->settings, 'onboarding', []);
        $alertEmail = data_get($tenant->settings, 'alerts.email');

        $workspaceSaved = filled($onboarding['workspace_completed_at'] ?? null)
            || (
                filled($tenant->name)
                && filled($tenant->timezone)
                && filled($tenant->country_code)
                && filled($alertEmail)
            );

        $hasSubscription = TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->exists();

        $planDone = filled($onboarding['plan_completed_at'] ?? null) || $hasSubscription;

        $hasHeartbeat = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('last_heartbeat_at')
            ->exists();

        $whmcsDone = $hasHeartbeat;

        $firstScanDone = filled($onboarding['first_scan_completed_at'] ?? null);

        return [
            'workspace' => $workspaceSaved,
            'plan' => $planDone,
            'whmcs' => $whmcsDone,
            'first_scan' => $firstScanDone,
        ];
    }

    /**
     * @return array{ready: bool, issues_count: int, one_click_count: int, has_analyzed_run: bool}
     */
    public function firstScanSummary(Tenant $tenant): array
    {
        $analyzedRun = DiagnosticRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'analyzed')
            ->latest('analysis_completed_at')
            ->first();

        if ($analyzedRun === null) {
            return [
                'ready' => false,
                'issues_count' => 0,
                'one_click_count' => 0,
                'has_analyzed_run' => false,
            ];
        }

        $issuesCount = DiagnosticFinding::query()
            ->where('diagnostic_run_id', $analyzedRun->id)
            ->where('tenant_id', $tenant->id)
            ->active()
            ->count();

        $oneClickCount = DiagnosticFinding::query()
            ->where('diagnostic_run_id', $analyzedRun->id)
            ->where('tenant_id', $tenant->id)
            ->active()
            ->where('fix_mode', 'one_click')
            ->count();

        return [
            'ready' => true,
            'issues_count' => $issuesCount,
            'one_click_count' => $oneClickCount,
            'has_analyzed_run' => true,
        ];
    }

    /**
     * Heartbeat wait status for the onboarding live checker.
     *
     * @return array{
     *     connected: bool,
     *     last_heartbeat_at: string|null,
     *     waiting_started_at: string|null,
     *     waiting_seconds: int,
     *     show_troubleshooting: bool
     * }
     */
    public function heartbeatStatus(Tenant $tenant): array
    {
        $integration = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('last_heartbeat_at')
            ->orderByDesc('created_at')
            ->first();

        $lastHeartbeatAt = $integration?->last_heartbeat_at;
        $waitingStartedAt = $integration !== null
            ? $integration->created_at
            : $tenant->created_at;
        $waitingSeconds = $waitingStartedAt === null
            ? 0
            : max(0, (int) now()->diffInSeconds($waitingStartedAt, absolute: true));

        return [
            'connected' => $lastHeartbeatAt !== null,
            'last_heartbeat_at' => $lastHeartbeatAt?->toIso8601String(),
            'waiting_started_at' => $waitingStartedAt?->toIso8601String(),
            'waiting_seconds' => $waitingSeconds,
            'show_troubleshooting' => $lastHeartbeatAt === null && $waitingSeconds >= 600,
        ];
    }

    /**
     * @param  array{workspace: bool, plan: bool, whmcs: bool, first_scan: bool}  $steps
     */
    private function persistDerivedFlags(Tenant $tenant, array $steps): void
    {
        $settings = (array) $tenant->settings;
        $onboarding = (array) ($settings['onboarding'] ?? []);
        $dirty = false;
        $now = now()->toIso8601String();

        foreach (['workspace', 'plan', 'whmcs', 'first_scan'] as $step) {
            $key = $step.'_completed_at';

            if ($steps[$step] && ! filled($onboarding[$key] ?? null)) {
                $onboarding[$key] = $now;
                $dirty = true;
            }
        }

        if (! $dirty) {
            return;
        }

        $settings['onboarding'] = $onboarding;
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  array{workspace: bool, plan: bool, whmcs: bool, first_scan: bool}  $steps
     */
    private function currentStep(array $steps): string
    {
        if (! $steps['workspace']) {
            return 'workspace';
        }

        if (! $steps['plan']) {
            return 'plan';
        }

        if (! $steps['whmcs']) {
            return 'whmcs';
        }

        if (! $steps['first_scan']) {
            return 'first_scan';
        }

        return 'done';
    }
}

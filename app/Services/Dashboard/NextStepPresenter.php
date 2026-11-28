<?php

namespace App\Services\Dashboard;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantAlertChannel;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\OnboardingProgress;

class NextStepPresenter
{
    public function __construct(
        private readonly OnboardingProgress $onboardingProgress,
        private readonly TopOpenFindingSelector $topOpenFindingSelector,
    ) {}

    /**
     * One prominent next action for the Home dashboard, highest priority first.
     *
     * @return array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     cta_label: string,
     *     cta_href: string
     * }|null
     */
    public function forUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $membership = $user->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('tenant')
            ->orderBy('created_at')
            ->first();

        if ($membership === null) {
            return null;
        }

        /** @var Tenant $tenant */
        $tenant = $membership->tenant;
        $progress = $this->onboardingProgress->forTenant($tenant);

        if ($progress['needed']) {
            $remaining = collect($progress['steps'])
                ->filter(fn (bool $done): bool => ! $done)
                ->count();

            return [
                'key' => 'finish_onboarding',
                'title' => 'Finish setup',
                'description' => $remaining === 1
                    ? '1 step left to finish workspace setup.'
                    : "{$remaining} steps left to finish workspace setup.",
                'cta_label' => 'Continue setup',
                'cta_href' => '/onboarding',
            ];
        }

        $integrations = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at')
            ->get(['id', 'last_heartbeat_at']);

        if ($integrations->isEmpty()) {
            return [
                'key' => 'connect_whmcs',
                'title' => 'Connect your WHMCS',
                'description' => 'Download the add-on, create a connection key, and paste it on your server. About 10 minutes.',
                'cta_label' => 'Connect WHMCS',
                'cta_href' => '/integrations',
            ];
        }

        $hasHeartbeat = $integrations->contains(
            fn (WhmcsIntegration $integration): bool => $integration->last_heartbeat_at !== null,
        );

        if (! $hasHeartbeat) {
            return [
                'key' => 'waiting_heartbeat',
                'title' => 'Waiting to hear from your WHMCS',
                'description' => 'Once the add-on and scheduled task are running, this updates by itself.',
                'cta_label' => 'Open setup steps',
                'cta_href' => '/whmcs-addon/setup',
            ];
        }

        $hasCheck = DiagnosticRun::query()
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (! $hasCheck) {
            return [
                'key' => 'waiting_first_check',
                'title' => 'Waiting for your first check',
                'description' => 'In WHMCS open the add-on and press Run diagnostics once, or wait for the next automatic check.',
                'cta_label' => 'Check history',
                'cta_href' => '/diagnostic-runs',
            ];
        }

        $topFinding = $this->topOpenFindingSelector->select(
            DiagnosticFinding::query()
                ->where('tenant_id', $tenant->id)
                ->currentIssues()
                ->active()
                ->notIgnored()
                ->get(),
        );

        if ($topFinding !== null && strtolower($topFinding['severity']) === 'critical') {
            return [
                'key' => 'fix_critical',
                'title' => 'Fix open critical issues',
                'description' => $topFinding['title'],
                'cta_label' => 'See what to fix',
                'cta_href' => '/findings/'.$topFinding['id'],
            ];
        }

        $hasAlertChannel = TenantAlertChannel::query()
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (! $hasAlertChannel) {
            return [
                'key' => 'add_alert_channel',
                'title' => 'Add an alert channel',
                'description' => 'Get alerts by email or Slack so you know the moment something breaks.',
                'cta_label' => 'Add alerts',
                'cta_href' => "/tenants/{$tenant->id}/alerts",
            ];
        }

        return [
            'key' => 'all_clear',
            'title' => 'All clear',
            'description' => 'Nothing needs you right now. We check your WHMCS automatically.',
            'cta_label' => 'View reports',
            'cta_href' => '/reports',
        ];
    }
}

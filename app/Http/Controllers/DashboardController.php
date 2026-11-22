<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Ai\OpenAiConfiguration;
use App\Services\Health\HealthDashboardPresenter;
use App\Services\Tenancy\OnboardingProgress;
use App\Services\Tenancy\TenantRecordQuery;
use App\Support\WhmcsAddonPackageSigner;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        WhmcsAddonPackageSigner $signer,
        OpenAiConfiguration $openAi,
        OnboardingProgress $onboardingProgress,
        HealthDashboardPresenter $healthPresenter,
    ): Response {
        /** @var User|null $user */
        $user = Auth::user();
        $tenantRecords = app(TenantRecordQuery::class);

        $latestRuns = $tenantRecords->runs($user)
            ->with('integration:id,name')
            ->withCount('findings')
            ->latest('received_at')
            ->limit(6)
            ->get()
            ->map(fn (DiagnosticRun $run): array => [
                'id' => $run->id,
                'snapshot_uuid' => $run->snapshot_uuid,
                'status' => $run->status,
                'collected_at' => $run->collected_at?->toIso8601String(),
                'received_at' => $run->received_at?->toIso8601String(),
                'whmcs_version' => data_get($run->payload, 'system.whmcs_version', 'Unknown'),
                'findings_count' => $run->findings_count,
                'integration_name' => $run->integration->name,
            ]);

        $signingConfigured = $signer->isConfigured();

        $installations = $healthPresenter->installationsStatus($user);

        $integrationIds = $tenantRecords->integrations($user)->pluck('id');

        $lastScanClean = $tenantRecords->runs($user)->where('status', 'analyzed')->exists()
            && ! $tenantRecords->findings($user)->currentIssues()->active()->exists();

        return Inertia::render('dashboard', [
            'stats' => [
                'active_integrations' => $tenantRecords->integrations($user)->where('status', 'active')->count(),
                'total_runs' => $tenantRecords->runs($user)->count(),
                'open_findings' => $tenantRecords->findings($user)->currentIssues()->active()->count(),
                'critical_findings' => $tenantRecords->findings($user)
                    ->currentIssues()
                    ->active()
                    ->where('severity', 'critical')
                    ->count(),
                'awaiting_safe_actions' => SafeActionExecution::query()
                    ->whereIn('integration_id', $integrationIds)
                    ->where('status', SafeActionExecution::STATUS_AWAITING_AGENT)
                    ->count(),
                'needs_recheck' => SafeActionExecution::query()
                    ->whereIn('integration_id', $integrationIds)
                    ->where('status', SafeActionExecution::STATUS_COMPLETED)
                    ->count(),
            ],
            'health' => $healthPresenter->healthCard($user, $lastScanClean),
            'needsYouNow' => $healthPresenter->needsYouNow($user),
            'timeSaved' => $healthPresenter->timeSavedThisMonth($user),
            'recovered' => $healthPresenter->recoveredThisMonth($user),
            'installations' => $installations,
            'latestRuns' => $latestRuns,
            'addon' => [
                'version' => config('services.whmcs_addon.version', '1.0.0'),
                'download_available' => class_exists(\ZipArchive::class) && $signingConfigured,
                'ai_configured' => $openAi->isConfigured(),
            ],
            'onboarding' => $this->onboardingChecklist($user, $onboardingProgress),
        ]);
    }

    /**
     * @return array{needed: bool, tenant_id: ?string, steps: array{workspace: bool, plan: bool, whmcs: bool}}|null
     */
    private function onboardingChecklist(?User $user, OnboardingProgress $onboardingProgress): ?array
    {
        if ($user === null) {
            return null;
        }

        $membership = $user->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->where('role', TenantMembership::ROLE_OWNER)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('tenant')
            ->orderBy('created_at')
            ->first();

        if ($membership === null) {
            return null;
        }

        /** @var Tenant $tenant */
        $tenant = $membership->tenant;
        $progress = $onboardingProgress->forTenant($tenant);

        return [
            'needed' => $progress['needed'],
            'tenant_id' => $progress['tenant_id'],
            'steps' => $progress['steps'],
        ];
    }
}

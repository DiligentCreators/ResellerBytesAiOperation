<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Ai\OpenAiConfiguration;
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

        return Inertia::render('dashboard', [
            'stats' => [
                'active_integrations' => $tenantRecords->integrations($user)->where('status', 'active')->count(),
                'total_runs' => $tenantRecords->runs($user)->count(),
                'open_findings' => $tenantRecords->findings($user)->where('status', 'open')->count(),
                'critical_findings' => $tenantRecords->findings($user)
                    ->where('status', 'open')
                    ->where('severity', 'critical')
                    ->count(),
            ],
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

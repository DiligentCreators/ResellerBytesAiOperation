<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenants\UpdateAutoFixPolicyRequest;
use App\Models\AutoFixPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionRegistry;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantAutoFixSettingsController extends Controller
{
    public function show(
        Request $request,
        Tenant $tenant,
        TenantRoleAuthorization $authorization,
        TenantEntitlementService $entitlements,
        SafeActionRegistry $registry,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $membership = $authorization->require($user, $tenant->id, 'tenant.read');
        $canManage = $authorization->allows($membership, 'tenant.settings.manage');

        $autoEnabled = $this->hasAutoEntitlement($entitlements, $tenant->id);

        $installations = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'status', 'auto_collect_enabled', 'last_heartbeat_at'])
            ->map(fn (WhmcsIntegration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'status' => $integration->status,
                'auto_collect_enabled' => (bool) $integration->auto_collect_enabled,
                'last_heartbeat_at' => $integration->last_heartbeat_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $policies = AutoFixPolicy::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('action_key')
            ->orderBy('integration_id')
            ->get()
            ->map(fn (AutoFixPolicy $policy): array => [
                'id' => $policy->id,
                'action_key' => $policy->action_key,
                'integration_id' => $policy->integration_id,
                'enabled' => (bool) $policy->enabled,
                'daily_cap' => $policy->daily_cap,
            ])
            ->values()
            ->all();

        $actions = [];
        foreach ($registry->all() as $definition) {
            if (! ($definition['auto_allowed'] ?? false)) {
                continue;
            }

            $actions[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'risk_level' => $definition['risk_level'],
            ];
        }

        return Inertia::render('tenants/auto-fix', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'canManage' => $canManage,
            'proEnabled' => $autoEnabled,
            'billingPlansUrl' => route('billing.plans'),
            'integrationsUrl' => route('integrations.index'),
            'installations' => $installations,
            'actions' => $actions,
            'policies' => $policies,
            'updateUrl' => route('tenants.auto-fix.update', $tenant),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(
        UpdateAutoFixPolicyRequest $request,
        Tenant $tenant,
        TenantRoleAuthorization $authorization,
        TenantEntitlementService $entitlements,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.settings.manage');
        $entitlements->requireFeature($tenant->id, 'fixes.auto');

        foreach ($request->validated('policies') as $row) {
            $integrationId = $row['integration_id'] ?? null;

            $policy = AutoFixPolicy::query()
                ->where('tenant_id', $tenant->id)
                ->where('action_key', $row['action_key'])
                ->when(
                    is_string($integrationId) && $integrationId !== '',
                    fn ($query) => $query->where('integration_id', $integrationId),
                    fn ($query) => $query->whereNull('integration_id'),
                )
                ->first();

            if ($policy === null) {
                AutoFixPolicy::query()->create([
                    'tenant_id' => $tenant->id,
                    'integration_id' => is_string($integrationId) && $integrationId !== '' ? $integrationId : null,
                    'action_key' => $row['action_key'],
                    'enabled' => (bool) $row['enabled'],
                    'daily_cap' => $row['daily_cap'] ?? null,
                    'created_by_user_id' => $user->id,
                ]);

                continue;
            }

            $policy->forceFill([
                'enabled' => (bool) $row['enabled'],
                'daily_cap' => $row['daily_cap'] ?? null,
            ])->save();
        }

        return redirect()
            ->route('tenants.auto-fix.show', $tenant)
            ->with('status', 'Auto-fix policies updated.');
    }

    private function hasAutoEntitlement(TenantEntitlementService $entitlements, string $tenantId): bool
    {
        try {
            $entitlements->requireFeature($tenantId, 'fixes.auto');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}

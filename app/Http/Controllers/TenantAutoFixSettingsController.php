<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
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
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $membership = $authorization->require($user, $tenant->id, 'tenant.read');
        $canManage = $authorization->allows($membership, 'tenant.settings.manage');

        $oneClickEnabled = false;
        try {
            $entitlements->requireFeature($tenant->id, 'fixes.one_click');
            $oneClickEnabled = true;
        } catch (AuthorizationException) {
            $oneClickEnabled = false;
        }

        $installations = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
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

        return Inertia::render('tenants/auto-fix', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'canManage' => $canManage,
            'proEnabled' => $oneClickEnabled,
            'billingPlansUrl' => route('billing.plans'),
            'integrationsUrl' => route('integrations.index'),
            'installations' => $installations,
        ]);
    }
}

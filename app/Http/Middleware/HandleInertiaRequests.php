<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $authorization = app(CentralStaffAuthorization::class);
        $roleAuthorization = app(TenantRoleAuthorization::class);
        $user = $request->user();
        $memberships = $this->activeMemberships($user);
        $tenants = $memberships
            ->map(fn (TenantMembership $membership): array => [
                'id' => (string) $membership->tenant_id,
                'name' => (string) $membership->tenant->name,
                'slug' => (string) $membership->tenant->slug,
                'role' => (string) $membership->role,
            ])
            ->values()
            ->all();

        $twoFactorEnabled = $user instanceof User && $user->hasEnabledTwoFactorAuthentication();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'is_platform_admin' => $authorization->isSuperAdmin($user),
                'is_platform_operator' => $authorization->isPlatformOperator($user),
                'central_permissions' => $authorization->permissions($user),
                'tenants' => $tenants,
                'can_manage_integrations' => $memberships->contains(
                    fn (TenantMembership $membership): bool => $roleAuthorization->allows($membership, 'integrations.manage')
                ),
                'two_factor_enabled' => $twoFactorEnabled,
                'encourage_two_factor' => $user instanceof User && ! $twoFactorEnabled,
            ],
            'flash' => [
                'integration_credentials' => fn () => $request->session()->get('integration_credentials'),
                'status' => fn () => $request->session()->get('status'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return Collection<int, TenantMembership>
     */
    private function activeMemberships(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        return $user->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with(['tenant:id,name,slug,status'])
            ->orderBy('created_at')
            ->get();
    }
}

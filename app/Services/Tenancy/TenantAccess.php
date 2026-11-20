<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TenantAccess
{
    /**
     * Resolve an active tenant membership and optionally enforce one of its roles.
     * Missing/inactive tenant context and membership always fail closed.
     *
     * @param  array<int, string>  $allowedRoles
     */
    public function requireMembership(User $user, string $tenantId, array $allowedRoles = []): TenantMembership
    {
        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if ($tenant === null) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        $membership = $tenant->memberships()
            ->where('user_id', $user->getKey())
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->first();

        if ($membership === null || ! $membership->isActive()) {
            throw (new ModelNotFoundException)->setModel(TenantMembership::class, [$tenantId, $user->getKey()]);
        }

        if ($allowedRoles !== [] && ! $membership->hasAnyRole($allowedRoles)) {
            throw new AuthorizationException('The active tenant membership does not grant the required role.');
        }

        return $membership;
    }
}

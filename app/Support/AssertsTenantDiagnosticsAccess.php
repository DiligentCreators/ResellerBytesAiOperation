<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use App\Services\Tenancy\TenantRoleAuthorization;

/**
 * Platform billing-only staff must not browse tenant diagnostic surfaces as empty lists.
 * Regular workspace members (including billing role) keep the empty-200 pattern.
 */
final class AssertsTenantDiagnosticsAccess
{
    public static function forIndex(?User $user): void
    {
        if ($user === null) {
            abort(403);
        }

        $central = app(CentralStaffAuthorization::class);
        if (! $central->isPlatformOperator($user)) {
            return;
        }

        $roles = app(TenantRoleAuthorization::class)->rolesFor('diagnostics.read');
        $hasDiagnosticMembership = TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('role', $roles)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->exists();

        abort_unless($hasDiagnosticMembership, 403);
    }
}

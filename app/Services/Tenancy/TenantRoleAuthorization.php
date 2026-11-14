<?php

namespace App\Services\Tenancy;

use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class TenantRoleAuthorization
{
    /** @var array<string, array<int, string>> */
    private const CAPABILITIES = [
        'tenant.read' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER, TenantMembership::ROLE_BILLING],
        'diagnostics.read' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_VIEWER],
        'diagnostics.reanalyze' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_OPERATOR],
        'integrations.manage' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_ADMIN],
        'members.manage' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_ADMIN],
        'billing.read' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_ADMIN, TenantMembership::ROLE_BILLING],
        'billing.manage' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_BILLING],
        'tenant.settings.manage' => [TenantMembership::ROLE_OWNER, TenantMembership::ROLE_ADMIN],
        'tenant.close' => [TenantMembership::ROLE_OWNER],
    ];

    public function allows(TenantMembership $membership, string $capability): bool
    {
        return $membership->isActive() && in_array($membership->role, self::CAPABILITIES[$capability] ?? [], true);
    }

    public function require(User $user, string $tenantId, string $capability): TenantMembership
    {
        $membership = app(TenantAccess::class)->requireMembership($user, $tenantId);
        if (! $this->allows($membership, $capability)) {
            throw new AuthorizationException('The active tenant role does not grant this capability.');
        }
        return $membership;
    }
}

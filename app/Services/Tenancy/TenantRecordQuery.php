<?php

namespace App\Services\Tenancy;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the transitional account boundary to unassigned legacy records and
 * the membership boundary to tenant-assigned records. Tenant ownership always
 * takes precedence over the historical integration owner field.
 */
class TenantRecordQuery
{
    /** @return Builder<WhmcsIntegration> */
    public function integrations(User $user): Builder
    {
        return WhmcsIntegration::query()->where(function (Builder $query) use ($user): void {
            $query->where(function (Builder $legacy) use ($user): void {
                $legacy->whereNull('tenant_id')->where('owner_user_id', $user->getKey());
            })->orWhere(function (Builder $tenantOwned) use ($user): void {
                $tenantOwned->whereNotNull('tenant_id')->whereHas('tenant', function (Builder $tenant) use ($user): void {
                    $tenant->where('status', Tenant::STATUS_ACTIVE)
                        ->whereHas('memberships', function (Builder $membership) use ($user): void {
                            $membership->where('user_id', $user->getKey())
                                ->where('status', TenantMembership::STATUS_ACTIVE)
                                ->whereIn('role', TenantMembership::ROLES);
                        });
                });
            });
        });
    }

    /** @return Builder<DiagnosticRun> */
    public function runs(User $user): Builder
    {
        return DiagnosticRun::query()->whereHas('integration', function (Builder $integration) use ($user): void {
            $integration->where(function (Builder $scope) use ($user): void {
                $scope->where(function (Builder $legacy) use ($user): void {
                    $legacy->whereNull('tenant_id')->where('owner_user_id', $user->getKey());
                })->orWhere(function (Builder $tenantOwned) use ($user): void {
                    $tenantOwned->whereNotNull('tenant_id')->whereHas('tenant', function (Builder $tenant) use ($user): void {
                        $tenant->where('status', Tenant::STATUS_ACTIVE)
                            ->whereHas('memberships', function (Builder $membership) use ($user): void {
                                $membership->where('user_id', $user->getKey())
                                    ->where('status', TenantMembership::STATUS_ACTIVE)
                                    ->whereIn('role', TenantMembership::ROLES);
                            });
                    });
                });
            });
        });
    }

    /** @return Builder<DiagnosticFinding> */
    public function findings(User $user): Builder
    {
        return DiagnosticFinding::query()->whereHas('diagnosticRun.integration', function (Builder $integration) use ($user): void {
            $integration->where(function (Builder $scope) use ($user): void {
                $scope->where(function (Builder $legacy) use ($user): void {
                    $legacy->whereNull('tenant_id')->where('owner_user_id', $user->getKey());
                })->orWhere(function (Builder $tenantOwned) use ($user): void {
                    $tenantOwned->whereNotNull('tenant_id')->whereHas('tenant', function (Builder $tenant) use ($user): void {
                        $tenant->where('status', Tenant::STATUS_ACTIVE)
                            ->whereHas('memberships', function (Builder $membership) use ($user): void {
                                $membership->where('user_id', $user->getKey())
                                    ->where('status', TenantMembership::STATUS_ACTIVE)
                                    ->whereIn('role', TenantMembership::ROLES);
                            });
                    });
                });
            });
        });
    }
}

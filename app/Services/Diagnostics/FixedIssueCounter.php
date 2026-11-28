<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantRecordQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single definition of a "fixed issue" shared by Fix Center, Billing, and reports.
 *
 * A fixed issue is the current copy of an issue (latest per integration + fingerprint),
 * not merged into a duplicate, with status resolved. Accepted risk is not fixed.
 */
class FixedIssueCounter
{
    public function __construct(
        private readonly TenantRecordQuery $tenantRecords,
    ) {}

    /**
     * Apply the shared fixed-issue constraints to an already-scoped findings query.
     *
     * @param  Builder<DiagnosticFinding>  $query
     * @return Builder<DiagnosticFinding>
     */
    public function constrain(
        Builder $query,
        ?CarbonInterface $resolvedFrom = null,
        ?CarbonInterface $resolvedTo = null,
    ): Builder {
        $query->fixedIssues();

        if ($resolvedFrom !== null) {
            $query->where('resolved_at', '>=', $resolvedFrom->copy()->utc());
        }

        if ($resolvedTo !== null) {
            $query->where('resolved_at', '<=', $resolvedTo->copy()->utc());
        }

        return $query;
    }

    /**
     * Fix Center scope: findings visible to the user via TenantRecordQuery.
     *
     * @return Builder<DiagnosticFinding>
     */
    public function queryForUser(
        ?User $user,
        ?CarbonInterface $resolvedFrom = null,
        ?CarbonInterface $resolvedTo = null,
    ): Builder {
        $base = $user === null
            ? DiagnosticFinding::query()->whereRaw('0 = 1')
            : $this->tenantRecords->findings($user);

        return $this->constrain($base, $resolvedFrom, $resolvedTo);
    }

    /**
     * Tenant-scoped query used by Billing and period reports.
     *
     * @return Builder<DiagnosticFinding>
     */
    public function queryForTenant(
        Tenant $tenant,
        ?CarbonInterface $resolvedFrom = null,
        ?CarbonInterface $resolvedTo = null,
    ): Builder {
        return $this->constrain(
            DiagnosticFinding::query()->where('tenant_id', $tenant->id),
            $resolvedFrom,
            $resolvedTo,
        );
    }

    public function countForUser(
        ?User $user,
        ?CarbonInterface $resolvedFrom = null,
        ?CarbonInterface $resolvedTo = null,
    ): int {
        return $this->queryForUser($user, $resolvedFrom, $resolvedTo)->count();
    }

    public function countForTenant(
        Tenant $tenant,
        ?CarbonInterface $resolvedFrom = null,
        ?CarbonInterface $resolvedTo = null,
    ): int {
        return $this->queryForTenant($tenant, $resolvedFrom, $resolvedTo)->count();
    }
}

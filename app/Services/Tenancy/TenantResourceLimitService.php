<?php

namespace App\Services\Tenancy;

use App\Models\TenantSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Enforces limits from the immutable entitlement snapshot on the active
 * subscription. Callers must perform the protected write in the same
 * transaction after this method returns when creating a countable resource.
 */
class TenantResourceLimitService
{
    public function requireIntegrationCapacity(string $tenantId): TenantSubscription
    {
        return $this->withinLockedSubscription($tenantId, function (TenantSubscription $subscription) use ($tenantId): TenantSubscription {
            $limit = $this->limit($subscription, 'integrations');
            $used = DB::table('whmcs_integrations')->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->count();

            if ($used >= $limit) {
                throw new AuthorizationException('The tenant integration limit has been reached.');
            }

            return $subscription;
        });
    }

    public function requireMemberCapacity(string $tenantId): TenantSubscription
    {
        return $this->withinLockedSubscription($tenantId, function (TenantSubscription $subscription) use ($tenantId): TenantSubscription {
            $limit = $this->limit($subscription, 'members');
            $used = DB::table('tenant_memberships')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['active', 'invited', 'suspended'])
                ->count();

            if ($used >= $limit) {
                throw new AuthorizationException('The tenant member limit has been reached.');
            }

            return $subscription;
        });
    }

    public function requireDiagnosticCapacity(string $tenantId): TenantSubscription
    {
        return $this->withinLockedSubscription($tenantId, function (TenantSubscription $subscription) use ($tenantId): TenantSubscription {
            $limit = $this->limit($subscription, 'diagnostics_per_period');
            $used = DB::table('diagnostic_runs')
                ->where('tenant_id', $tenantId)
                ->where('received_at', '>=', $subscription->current_period_starts_at)
                ->where('received_at', '<', $subscription->current_period_ends_at)
                ->count();

            if ($used >= $limit) {
                throw new AuthorizationException('The tenant diagnostic allowance for this billing period has been exhausted.');
            }

            return $subscription;
        });
    }

    private function withinLockedSubscription(string $tenantId, callable $callback): TenantSubscription
    {
        return DB::transaction(function () use ($tenantId, $callback): TenantSubscription {
            $subscription = app(TenantEntitlementService::class)->activeSubscription($tenantId);
            $locked = TenantSubscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();

            // Re-resolve after acquiring the lock so a stale/cancelled entitlement
            // cannot authorize a resource write.
            $current = app(TenantEntitlementService::class)->activeSubscription($tenantId);
            if ($current->getKey() !== $locked->getKey()) {
                throw new AuthorizationException('The active tenant subscription changed during quota validation.');
            }

            return $callback($locked);
        }, 3);
    }

    private function limit(TenantSubscription $subscription, string $key): int
    {
        $value = $subscription->entitlements['limits'][$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new AuthorizationException('The tenant resource limit is not configured in the active entitlement snapshot.');
        }

        return $value;
    }
}

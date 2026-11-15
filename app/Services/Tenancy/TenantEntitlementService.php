<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TenantEntitlementService
{
    public function activeSubscription(string $tenantId): TenantSubscription
    {
        $tenant = Tenant::query()->whereKey($tenantId)->where('status', Tenant::STATUS_ACTIVE)->first();
        if ($tenant === null) {
            throw (new ModelNotFoundException())->setModel(Tenant::class, [$tenantId]);
        }

        $subscription = TenantSubscription::query()->where('tenant_id', $tenantId)
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->where('current_period_starts_at', '<=', now())
            ->where(function ($query): void {
                $query->where('current_period_ends_at', '>', now())
                    ->orWhere(function ($grace): void {
                        $grace->where('status', 'past_due')->where('grace_ends_at', '>', now());
                    });
            })
            ->latest('id')->first();

        if ($subscription === null) {
            throw new AuthorizationException('An active tenant subscription is required for this operation.');
        }

        return $subscription;
    }

    public function requireFeature(string $tenantId, string $feature): TenantSubscription
    {
        $subscription = $this->activeSubscription($tenantId);
        if (($subscription->entitlements[$feature] ?? false) !== true) {
            throw new AuthorizationException('The current tenant subscription does not include this feature.');
        }
        return $subscription;
    }

    public function requireLimit(string $tenantId, string $limit, int $requested): TenantSubscription
    {
        $subscription = $this->activeSubscription($tenantId);
        $maximum = $subscription->entitlements['limits'][$limit] ?? null;
        if (! is_int($maximum) || $maximum < 0 || $requested < 0 || $requested > $maximum) {
            throw new AuthorizationException('The current tenant subscription limit does not permit this operation.');
        }
        return $subscription;
    }
}

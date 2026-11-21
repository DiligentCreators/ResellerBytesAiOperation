<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;

class TrialPolicy
{
    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * Eligible trial length in days for this tenant/plan, or 0 when not eligible.
     */
    public function trialDaysFor(Tenant $tenant, Plan $plan): int
    {
        if (! $this->settings->trialsEnabled()) {
            return 0;
        }

        if (! $plan->trial_enabled || $plan->trial_days < 1) {
            return 0;
        }

        if ($this->settings->trialFirstTimeOnly() && $this->tenantHasAnySubscription($tenant)) {
            return 0;
        }

        return (int) $plan->trial_days;
    }

    public function isEligible(Tenant $tenant, Plan $plan): bool
    {
        return $this->trialDaysFor($tenant, $plan) >= 1;
    }

    /**
     * Marketing surfaces (no tenant yet): show trial when global + plan trial are on.
     */
    public function marketingTrialDays(Plan $plan): int
    {
        if (! $this->settings->trialsEnabled()) {
            return 0;
        }

        if (! $plan->trial_enabled || $plan->trial_days < 1) {
            return 0;
        }

        return (int) $plan->trial_days;
    }

    public function cardRequired(): bool
    {
        return $this->settings->trialCardRequired();
    }

    private function tenantHasAnySubscription(Tenant $tenant): bool
    {
        return TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->exists();
    }
}

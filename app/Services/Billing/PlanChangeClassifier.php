<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\TenantSubscription;

class PlanChangeClassifier
{
    /**
     * A downgrade is any target with a lower normalized monthly price, or any
     * switch from yearly → monthly (even to a pricier plan). Monthly → yearly
     * is always an upgrade (immediate, prorated).
     */
    public function isDowngrade(
        TenantSubscription $current,
        Plan $targetPlan,
        string $targetPeriod,
    ): bool {
        $currentPeriod = $this->chargePeriodFor($current);

        if ($currentPeriod === 'yearly' && $targetPeriod === 'monthly') {
            return true;
        }

        if ($currentPeriod === 'monthly' && $targetPeriod === 'yearly') {
            return false;
        }

        return $this->normalizedMonthlyMinor($targetPlan, $targetPeriod)
            < $this->normalizedMonthlyMinorFromSubscription($current, $currentPeriod);
    }

    public function chargePeriodFor(TenantSubscription $subscription): string
    {
        $plan = $subscription->plan;
        if ($plan !== null && $subscription->unit_amount_minor === $plan->price_yearly_minor) {
            return 'yearly';
        }

        return 'monthly';
    }

    public function normalizedMonthlyMinor(Plan $plan, string $period): int
    {
        if ($period === 'yearly') {
            return intdiv($plan->price_yearly_minor, 12);
        }

        return $plan->price_monthly_minor;
    }

    public function normalizedMonthlyMinorFromSubscription(TenantSubscription $subscription, string $period): int
    {
        if ($period === 'yearly') {
            return intdiv((int) $subscription->unit_amount_minor, 12);
        }

        return (int) $subscription->unit_amount_minor;
    }
}

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

    /**
     * Prefer the price snapshot's stored charge_period.
     *
     * Fallback (legacy null rows only): infer yearly only when unit_amount_minor
     * equals the plan's *current* yearly price and does not also equal the monthly
     * price. After a catalog price edit those amounts may no longer match the
     * snapshot, so the fallback defaults to monthly rather than misclassify.
     * Run billing:backfill-tenant-subscription-charge-periods for null rows.
     */
    public function chargePeriodFor(TenantSubscription $subscription): string
    {
        if (in_array($subscription->charge_period, ['monthly', 'yearly'], true)) {
            return $subscription->charge_period;
        }

        return $this->inferChargePeriod($subscription);
    }

    /**
     * Legacy inference used for backfill and null charge_period rows.
     * Unsafe after plan price edits — prefer stored charge_period.
     */
    public function inferChargePeriod(TenantSubscription $subscription): string
    {
        $plan = $subscription->plan;
        if ($plan !== null
            && $subscription->unit_amount_minor === $plan->price_yearly_minor
            && $subscription->unit_amount_minor !== $plan->price_monthly_minor) {
            return 'yearly';
        }

        return 'monthly';
    }

    public function normalizedMonthlyMinor(Plan $plan, string $period): int
    {
        if ($period === 'yearly') {
            return intdiv($plan->priceForPeriod('yearly'), 12);
        }

        return $plan->priceForPeriod('monthly');
    }

    public function normalizedMonthlyMinorFromSubscription(TenantSubscription $subscription, string $period): int
    {
        if ($period === 'yearly') {
            return intdiv((int) $subscription->unit_amount_minor, 12);
        }

        return (int) $subscription->unit_amount_minor;
    }
}

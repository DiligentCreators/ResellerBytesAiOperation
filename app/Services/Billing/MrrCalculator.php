<?php

namespace App\Services\Billing;

use App\Models\TenantSubscription;
use Illuminate\Support\Collection;

class MrrCalculator
{
    /**
     * Statuses counted toward approximate MRR on the platform billing overview.
     * Matches the page's historical filter (trialing / active / past_due).
     * cancel_at_period_end subscriptions remain counted while in these statuses
     * because they still pay until period end. Canceled, incomplete, suspended,
     * and other ended statuses are excluded.
     *
     * @var list<string>
     */
    public const COUNTED_STATUSES = ['trialing', 'active', 'past_due'];

    public function __construct(private PlanChangeClassifier $classifier) {}

    /**
     * Approximate MRR in integer minor units.
     *
     * Interval comes from PlanChangeClassifier::chargePeriodFor (stored
     * charge_period with inference fallback). Yearly amounts are divided by 12;
     * monthly amounts are unchanged. Exact monthly fractions are summed, then
     * rounded half-up once on the total.
     */
    public function approximateMrrMinor(): int
    {
        /** @var Collection<int, TenantSubscription> $subscriptions */
        $subscriptions = TenantSubscription::query()
            ->with('plan:id,price_yearly_minor')
            ->whereIn('status', self::COUNTED_STATUSES)
            ->get(['id', 'plan_id', 'unit_amount_minor', 'charge_period', 'status']);

        return $this->fromSubscriptions($subscriptions);
    }

    /**
     * @param  iterable<int, TenantSubscription>  $subscriptions
     */
    public function fromSubscriptions(iterable $subscriptions): int
    {
        // Accumulate in (minor units × 12) so yearly and monthly share one divisor.
        $scaled = 0;

        foreach ($subscriptions as $subscription) {
            $amount = (int) $subscription->unit_amount_minor;
            $period = $this->classifier->chargePeriodFor($subscription);
            $scaled += $period === 'yearly' ? $amount : ($amount * 12);
        }

        // Half-up round of scaled / 12 (same integer pattern as ProrationCalculator).
        return intdiv(($scaled * 2) + 12, 24);
    }
}

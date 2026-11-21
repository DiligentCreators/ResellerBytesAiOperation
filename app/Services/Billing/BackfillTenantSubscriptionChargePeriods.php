<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Idempotent backfill of tenant_subscriptions.charge_period from plan price inference.
 */
class BackfillTenantSubscriptionChargePeriods
{
    /**
     * Infer and store charge_period for rows where it is still null.
     *
     * @return int Number of rows updated
     */
    public function __invoke(int $chunkSize = 200): int
    {
        $updated = 0;

        DB::table('tenant_subscriptions')
            ->whereNull('charge_period')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($subscriptions) use (&$updated): void {
                $planIds = $subscriptions->pluck('plan_id')->filter()->unique()->values()->all();
                $plans = $planIds === []
                    ? collect()
                    : DB::table('plans')->whereIn('id', $planIds)->get(['id', 'price_yearly_minor'])->keyBy('id');

                foreach ($subscriptions as $subscription) {
                    $plan = $subscription->plan_id !== null
                        ? $plans->get($subscription->plan_id)
                        : null;

                    $period = ($plan !== null
                        && (int) $subscription->unit_amount_minor === (int) $plan->price_yearly_minor)
                        ? 'yearly'
                        : 'monthly';

                    $affected = DB::table('tenant_subscriptions')
                        ->where('id', $subscription->id)
                        ->whereNull('charge_period')
                        ->update(['charge_period' => $period]);

                    $updated += $affected;
                }
            });

        return $updated;
    }
}

<?php

use App\Models\Plan;
use App\Models\TenantSubscription;
use App\Services\Billing\MrrCalculator;

/**
 * @param  array{unit_amount_minor: int, price_monthly_minor?: int, price_yearly_minor?: int, status?: string}  $attrs
 */
function mrrSubscription(array $attrs): TenantSubscription
{
    $monthly = $attrs['price_monthly_minor'] ?? 4900;
    $yearly = $attrs['price_yearly_minor'] ?? 149000;

    $plan = new Plan([
        'price_monthly_minor' => $monthly,
        'price_yearly_minor' => $yearly,
    ]);

    $subscription = new TenantSubscription([
        'unit_amount_minor' => $attrs['unit_amount_minor'],
        'status' => $attrs['status'] ?? 'active',
    ]);
    $subscription->setRelation('plan', $plan);

    return $subscription;
}

it('normalises yearly subscriptions to monthly and rounds half-up once on the total', function (): void {
    $subscriptions = [
        // $49 / month
        mrrSubscription([
            'unit_amount_minor' => 4900,
            'price_monthly_minor' => 4900,
            'price_yearly_minor' => 49000,
        ]),
        // $149 / month
        mrrSubscription([
            'unit_amount_minor' => 14900,
            'price_monthly_minor' => 14900,
            'price_yearly_minor' => 149000,
        ]),
        // $1,490 / year → 12416.666… monthly
        mrrSubscription([
            'unit_amount_minor' => 149000,
            'price_monthly_minor' => 14900,
            'price_yearly_minor' => 149000,
        ]),
        // $4,990 / year → 41583.333… monthly
        mrrSubscription([
            'unit_amount_minor' => 499000,
            'price_monthly_minor' => 49900,
            'price_yearly_minor' => 499000,
        ]),
    ];

    // 4900 + 14900 + 149000/12 + 499000/12 = 73800
    expect(app(MrrCalculator::class)->fromSubscriptions($subscriptions))->toBe(73800);
});

it('rounds a single yearly hundred dollars to eight thirty-three', function (): void {
    $subscriptions = [
        mrrSubscription([
            'unit_amount_minor' => 10000,
            'price_monthly_minor' => 1000,
            'price_yearly_minor' => 10000,
        ]),
    ];

    // 10000 / 12 = 833.333… → half-up once → 833
    expect(app(MrrCalculator::class)->fromSubscriptions($subscriptions))->toBe(833);
});

it('treats monthly amounts as-is when the stored price matches the plan monthly price', function (): void {
    $subscriptions = [
        mrrSubscription([
            'unit_amount_minor' => 4900,
            'price_monthly_minor' => 4900,
            'price_yearly_minor' => 49000,
        ]),
    ];

    expect(app(MrrCalculator::class)->fromSubscriptions($subscriptions))->toBe(4900);
});

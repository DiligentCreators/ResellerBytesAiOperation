<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\MrrCalculator;
use App\Services\Billing\PlanChangeClassifier;
use App\Services\Billing\SubscriptionManagementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;

function chargePeriodBillingDetails(): array
{
    return [
        'company_name' => 'Charge Period Co',
        'billing_email' => 'charge-period@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ];
}

function chargePeriodOwnerTenant(): array
{
    $tenant = Tenant::create([
        'name' => 'Charge Period Tenant',
        'slug' => 'charge-period-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    return [$tenant, $owner];
}

it('stores charge_period on checkout create for monthly and yearly', function (): void {
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    [$tenant, $owner] = chargePeriodOwnerTenant();
    $plan = Plan::query()->where('code', 'growth')->firstOrFail();

    $monthly = app(CheckoutService::class)->begin(
        $tenant->id,
        $owner,
        $plan,
        'monthly',
        chargePeriodBillingDetails(),
    );
    expect($monthly['subscription']->charge_period)->toBe('monthly');

    $other = Tenant::create([
        'name' => 'Charge Period Yearly Tenant',
        'slug' => 'charge-period-y-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $other->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $yearly = app(CheckoutService::class)->begin(
        $other->id,
        $owner,
        $plan,
        'yearly',
        chargePeriodBillingDetails(),
    );
    expect($yearly['subscription']->charge_period)->toBe('yearly');
});

it('stores charge_period on upgrade replacement and scheduled-change apply', function (): void {
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    [$tenant, $owner] = chargePeriodOwnerTenant();
    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $growth = Plan::query()->where('code', 'growth')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin(
        $tenant->id,
        $owner,
        $starter,
        'monthly',
        chargePeriodBillingDetails(),
    );
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);
    $current = $checkout['subscription']->fresh();

    $upgrade = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $growth,
        'yearly',
        chargePeriodBillingDetails(),
    );
    expect($upgrade['subscription']->charge_period)->toBe('yearly');

    app(SandboxPaymentGateway::class)->approve($upgrade['invoice']);
    $active = $upgrade['subscription']->fresh();

    // Schedule a downgrade then apply it.
    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $active->id,
        $owner,
        $starter,
        'monthly',
        chargePeriodBillingDetails(),
    );

    $active->forceFill([
        'scheduled_change_at' => CarbonImmutable::now('UTC')->subMinute(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->subMinute(),
    ])->save();

    $result = app(SubscriptionManagementService::class)->applyDueScheduledChanges();
    expect($result['applied'])->toBe(1);

    $replacement = TenantSubscription::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', 'active')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($replacement->charge_period)->toBe('monthly')
        ->and($replacement->plan_code)->toBe('starter');
});

it('uses stored charge_period for MRR even when price would infer differently', function (): void {
    $plan = Plan::factory()->create([
        'code' => 'mrr-stored-period',
        'price_monthly_minor' => 10000,
        'price_yearly_minor' => 100000,
    ]);

    // Amount matches monthly price, but subscription is stored as yearly.
    $subscription = new TenantSubscription([
        'unit_amount_minor' => 10000,
        'charge_period' => 'yearly',
        'status' => 'active',
    ]);
    $subscription->setRelation('plan', $plan);

    expect(app(PlanChangeClassifier::class)->chargePeriodFor($subscription))->toBe('yearly')
        ->and(app(MrrCalculator::class)->fromSubscriptions([$subscription]))->toBe(833);
});

it('falls back to price inference when charge_period is null', function (): void {
    $plan = Plan::factory()->create([
        'code' => 'mrr-null-period',
        'price_monthly_minor' => 14900,
        'price_yearly_minor' => 149000,
    ]);

    $yearly = new TenantSubscription([
        'unit_amount_minor' => 149000,
        'charge_period' => null,
        'status' => 'active',
    ]);
    $yearly->setRelation('plan', $plan);

    $monthly = new TenantSubscription([
        'unit_amount_minor' => 14900,
        'charge_period' => null,
        'status' => 'active',
    ]);
    $monthly->setRelation('plan', $plan);

    $classifier = app(PlanChangeClassifier::class);
    expect($classifier->chargePeriodFor($yearly))->toBe('yearly')
        ->and($classifier->chargePeriodFor($monthly))->toBe('monthly');
});

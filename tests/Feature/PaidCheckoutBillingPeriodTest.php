<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\SubscriptionManagementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;

function paidPeriodFixture(): array
{
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Paid Period Tenant',
        'slug' => 'paid-period-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    expect($starter->trial_days)->toBeGreaterThan(0);

    return [$tenant, $user, $starter];
}

function paidPeriodDetails(): array
{
    return [
        'company_name' => 'Period Co',
        'billing_email' => 'period@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ];
}

it('sets a full monthly paid period even when the plan has trial_days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 23:28:00', 'UTC'));
    [$tenant, $user, $plan] = paidPeriodFixture();

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        paidPeriodDetails(),
    );

    expect($result['subscription']->status)->toBe('incomplete')
        ->and($result['subscription']->current_period_ends_at->equalTo(
            CarbonImmutable::parse('2026-12-20 23:28:00', 'UTC'),
        ))->toBeTrue();

    app(SandboxPaymentGateway::class)->approve($result['invoice']);

    $active = $result['subscription']->fresh();
    expect($active->status)->toBe('active')
        ->and($active->current_period_ends_at->equalTo(
            CarbonImmutable::parse('2026-12-20 23:28:00', 'UTC'),
        ))->toBeTrue()
        ->and($active->current_period_starts_at->diffInDays($active->current_period_ends_at))->toBeGreaterThan(27);
});

it('sets a full yearly paid period even when the plan has trial_days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
    [$tenant, $user, $plan] = paidPeriodFixture();

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'yearly',
        paidPeriodDetails(),
    );

    expect($result['subscription']->current_period_ends_at->equalTo(
        CarbonImmutable::parse('2027-11-20 12:00:00', 'UTC'),
    ))->toBeTrue();

    app(SandboxPaymentGateway::class)->approve($result['invoice']);

    expect($result['subscription']->fresh()->current_period_ends_at->equalTo(
        CarbonImmutable::parse('2027-11-20 12:00:00', 'UTC'),
    ))->toBeTrue();
});

it('uses a full billing period for plan changes and renews from that paid end', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15 10:00:00', 'UTC'));
    [$tenant, $user, $starter] = paidPeriodFixture();
    $growth = Plan::query()->where('code', 'growth')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $user, $starter, 'monthly', paidPeriodDetails());
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);
    $current = $checkout['subscription']->fresh();

    expect($current->current_period_ends_at->equalTo(
        CarbonImmutable::parse('2026-02-15 10:00:00', 'UTC'),
    ))->toBeTrue();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-20 10:00:00', 'UTC'));

    $change = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $user,
        $growth,
        'yearly',
        paidPeriodDetails(),
    );

    expect($change['subscription']->current_period_ends_at->equalTo(
        CarbonImmutable::parse('2027-01-20 10:00:00', 'UTC'),
    ))->toBeTrue();

    app(SandboxPaymentGateway::class)->approve($change['invoice']);

    $active = $change['subscription']->fresh();
    expect($active->status)->toBe('active')
        ->and($active->current_period_ends_at->equalTo(
            CarbonImmutable::parse('2027-01-20 10:00:00', 'UTC'),
        ))->toBeTrue();

    // Renewal must start from the paid period end (no double-charge gap from trial_days).
    $renewalStart = $active->current_period_ends_at;
    $renewalEnd = $renewalStart->addYear();

    expect($renewalStart->equalTo(CarbonImmutable::parse('2027-01-20 10:00:00', 'UTC')))->toBeTrue()
        ->and($renewalEnd->equalTo(CarbonImmutable::parse('2028-01-20 10:00:00', 'UTC')))->toBeTrue();
});

it('does not leave a second current subscription when paid checkout is declined', function () {
    [$tenant, $user, $plan] = paidPeriodFixture();

    $result = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', paidPeriodDetails());
    app(SandboxPaymentGateway::class)->decline($result['invoice']);

    expect($result['subscription']->fresh()->status)->toBe('canceled');

    $currentCount = TenantSubscription::query()
        ->where('tenant_id', $tenant->id)
        ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended', 'incomplete'])
        ->count();

    expect($currentCount)->toBe(0);
});

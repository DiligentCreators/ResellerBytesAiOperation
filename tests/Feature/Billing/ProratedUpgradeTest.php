<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\ProrationCalculator;
use App\Services\Billing\SubscriptionManagementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;

function proratedUpgradeFixture(): array
{
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 12:00:00', 'UTC'));

    $tenant = Tenant::create([
        'name' => 'Proration Tenant',
        'slug' => 'proration-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $growth = Plan::query()->where('code', 'growth')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $user, $starter, 'monthly', [
        'company_name' => 'Pro Co',
        'billing_email' => 'pro@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    $subscription = $checkout['subscription']->fresh();
    // Mid-period window for predictable remaining days.
    $subscription->forceFill([
        'current_period_starts_at' => CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        'current_period_ends_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
    ])->save();

    return [$tenant, $user, $starter, $growth, $subscription->fresh()];
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('includes proration breakdown on the upgrade checkout page', function (): void {
    [$tenant, $user, $starter, $growth, $current] = proratedUpgradeFixture();

    $expected = app(ProrationCalculator::class)->forUpgrade(
        $growth->price_monthly_minor,
        (int) $current->unit_amount_minor,
        $current->current_period_starts_at,
        $current->current_period_ends_at,
        $growth->currency,
    );

    $this->actingAs($user)
        ->get(route('billing.checkout.show', [$tenant, $growth, 'period' => 'monthly']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/checkout')
            ->where('changeMode', 'upgrade')
            ->where('proration.total_due_minor', $expected->totalDueMinor)
            ->where('proration.credit_minor', $expected->creditMinor)
            ->where('proration.remaining_days', $expected->remainingDays)
            ->where('currentPlan.plan_code', 'starter'));
});

it('charges the gateway only for total_due and writes plan plus credit invoice lines', function (): void {
    [$tenant, $user, , $growth, $current] = proratedUpgradeFixture();

    $expected = app(ProrationCalculator::class)->forUpgrade(
        $growth->price_monthly_minor,
        (int) $current->unit_amount_minor,
        $current->current_period_starts_at,
        $current->current_period_ends_at,
        $growth->currency,
    );

    $change = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $user,
        $growth,
        'monthly',
        [
            'company_name' => 'Pro Co',
            'billing_email' => 'pro@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($change['mode'])->toBe('upgrade')
        ->and($change['invoice']->amount_due_minor)->toBe($expected->totalDueMinor)
        ->and($change['invoice']->subtotal_minor)->toBe($expected->newPriceMinor)
        ->and($change['proration']['total_due_minor'])->toBe($expected->totalDueMinor)
        ->and($change['invoice']->service_period_starts_at)->not->toBeNull()
        ->and($change['invoice']->line_items)->toHaveCount(2)
        ->and($change['invoice']->line_items[1]['amount_minor'])->toBe(-$expected->creditMinor);

    app(SandboxPaymentGateway::class)->approve($change['invoice']);

    expect($change['subscription']->fresh()->status)->toBe('active')
        ->and($change['subscription']->fresh()->plan_code)->toBe('growth')
        ->and($current->fresh()->status)->toBe('canceled')
        ->and($change['invoice']->fresh()->status)->toBe('paid')
        ->and($change['invoice']->fresh()->amount_paid_minor)->toBe($expected->totalDueMinor);
});

it('completes a zero-total upgrade without a gateway checkout', function (): void {
    [$tenant, $user, , $growth, $current] = proratedUpgradeFixture();

    // Monthly → yearly is always an upgrade. Day-1 unused credit ≈ current
    // unit_amount, so set yearly below that to land a zero-total upgrade.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
    $currentAmount = (int) $current->unit_amount_minor;
    $yearly = max(100, intdiv($currentAmount, 2));
    $current->forceFill([
        'current_period_starts_at' => CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        'current_period_ends_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
    ])->save();

    $growth->forceFill([
        'price_monthly_minor' => max(100, intdiv($currentAmount, 3)),
        'price_yearly_minor' => $yearly,
    ])->save();

    $change = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $user,
        $growth->fresh(),
        'yearly',
        [
            'company_name' => 'Pro Co',
            'billing_email' => 'pro@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($change['mode'])->toBe('zero_upgrade')
        ->and($change['checkout_url'])->toBeNull()
        ->and($change['invoice']->amount_due_minor)->toBe(0)
        ->and($change['invoice']->status)->toBe('paid')
        ->and($change['subscription']->fresh()->status)->toBe('active')
        ->and($change['subscription']->fresh()->plan_code)->toBe('growth')
        ->and($current->fresh()->status)->toBe('canceled')
        ->and(collect($change['invoice']->line_items)->sum('amount_minor'))->toBe(0);

    $this->actingAs($user)
        ->get(route('billing.settings.show', $tenant))
        ->assertOk();
});

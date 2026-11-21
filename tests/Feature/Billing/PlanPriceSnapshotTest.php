<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\MrrCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'operations.owner_email' => 'owner@example.test',
        'billing.default_gateway' => 'sandbox',
    ]);
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
});

function priceSnapshotFixture(): array
{
    $tenant = Tenant::create([
        'name' => 'Price Snapshot',
        'slug' => 'price-snap-'.uniqid(),
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
    $plan = Plan::factory()->create([
        'code' => 'snap-'.uniqid(),
        'price_monthly_minor' => 4900,
        'price_yearly_minor' => 49000,
        'is_active' => true,
        'is_public' => true,
        'is_contact_sales' => false,
        'monthly_active' => true,
        'yearly_active' => true,
        'prices_confirmed_at' => now(),
        'kelviq_plan_identifier_monthly' => 'kq_monthly',
        'kelviq_plan_identifier_yearly' => 'kq_yearly',
    ]);
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);

    return [$tenant, $user, $plan, $owner];
}

function priceSnapshotDetails(): array
{
    return [
        'company_name' => 'Snap Co',
        'billing_email' => 'snap@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ];
}

it('shows an edited price immediately on the pricing page and in a new checkout amount', function () {
    [$tenant, $user, $plan, $owner] = priceSnapshotFixture();

    $this->actingAs($owner)->put(route('platform.billing.plans.update', $plan), [
        'code' => $plan->code,
        'name' => $plan->name,
        'description' => $plan->description,
        'price_monthly' => '79.00',
        'price_yearly' => '790.00',
        'currency' => 'USD',
        'trial_enabled' => false,
        'trial_days' => 0,
        'sort_order' => $plan->sort_order,
        'monthly_active' => true,
        'yearly_active' => true,
        'is_active' => true,
        'is_public' => true,
        'is_contact_sales' => false,
        'entitlements' => $plan->entitlements,
    ])->assertRedirect();

    expect($plan->fresh()->price_monthly_minor)->toBe(7900);

    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/pricing')
            ->where('pricesArePlaceholders', false)
            ->where('plans', fn ($plans) => collect($plans)->contains(
                fn ($row) => $row['id'] === $plan->id && $row['price_monthly_minor'] === 7900,
            )));

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan->fresh(),
        'monthly',
        priceSnapshotDetails(),
    );

    expect($result['subscription']->unit_amount_minor)->toBe(7900)
        ->and($result['invoice']->amount_due_minor)->toBe(7900);
});

it('keeps existing subscription and invoice amounts after a plan price edit', function () {
    [$tenant, $user, $plan, $owner] = priceSnapshotFixture();

    $checkout = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        priceSnapshotDetails(),
    );
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    $subscription = $checkout['subscription']->fresh();
    $invoice = $checkout['invoice']->fresh();
    expect($subscription->unit_amount_minor)->toBe(4900)
        ->and($invoice->amount_due_minor)->toBe(4900)
        ->and($invoice->amount_paid_minor)->toBe(4900);

    $mrrBefore = app(MrrCalculator::class)->approximateMrrMinor();

    $this->actingAs($owner)->put(route('platform.billing.plans.update', $plan), [
        'code' => $plan->code,
        'name' => $plan->name,
        'description' => $plan->description,
        'price_monthly' => '199.00',
        'price_yearly' => '1990.00',
        'currency' => 'USD',
        'trial_enabled' => false,
        'trial_days' => 0,
        'sort_order' => $plan->sort_order,
        'monthly_active' => true,
        'yearly_active' => true,
        'is_active' => true,
        'is_public' => true,
        'is_contact_sales' => false,
        'entitlements' => $plan->entitlements,
    ])->assertRedirect();

    expect($subscription->fresh()->unit_amount_minor)->toBe(4900)
        ->and($invoice->fresh()->amount_due_minor)->toBe(4900)
        ->and($invoice->fresh()->amount_paid_minor)->toBe(4900)
        ->and($invoice->fresh()->subtotal_minor)->toBe(4900)
        ->and(app(MrrCalculator::class)->approximateMrrMinor())->toBe($mrrBefore);
});

it('rejects checkout for an inactive interval and hides it on pricing', function () {
    [$tenant, $user, $plan, $owner] = priceSnapshotFixture();

    $this->actingAs($owner)->put(route('platform.billing.plans.update', $plan), [
        'code' => $plan->code,
        'name' => $plan->name,
        'description' => $plan->description,
        'price_monthly' => '49.00',
        'price_yearly' => '490.00',
        'currency' => 'USD',
        'trial_enabled' => false,
        'trial_days' => 0,
        'sort_order' => $plan->sort_order,
        'monthly_active' => true,
        'yearly_active' => false,
        'is_active' => true,
        'is_public' => true,
        'is_contact_sales' => false,
        'entitlements' => $plan->entitlements,
    ])->assertRedirect();

    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plans', fn ($plans) => collect($plans)->contains(
            fn ($row) => $row['id'] === $plan->id
                && $row['monthly_active'] === true
                && $row['yearly_active'] === false,
        )));

    $this->actingAs($user)
        ->post(route('billing.checkout.store', [$tenant, $plan]), [
            ...priceSnapshotDetails(),
            'period' => 'yearly',
        ])
        ->assertSessionHasErrors('checkout');

    expect(fn () => app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan->fresh(),
        'yearly',
        priceSnapshotDetails(),
    ))->toThrow(InvalidArgumentException::class, 'billing_interval_inactive');
});

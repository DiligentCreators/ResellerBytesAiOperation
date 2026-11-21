<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Support\BillingCustomerMessages;
use Database\Seeders\PlanSeeder;

function planChangeHttpFixture(): array
{
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'HTTP Plan Change',
        'slug' => 'http-plan-change-'.uniqid(),
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
    $growth = Plan::query()->where('code', 'growth')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $user, $starter, 'monthly', [
        'company_name' => 'HTTP Co',
        'billing_email' => 'http@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    return [$tenant, $user, $starter, $growth, $checkout['subscription']->fresh()];
}

it('starts a plan-change checkout from billing checkout when a subscription is already active', function () {
    [$tenant, $user, , $growth, $current] = planChangeHttpFixture();

    $response = $this->actingAs($user)->post(route('billing.checkout.store', [$tenant, $growth]), [
        'period' => 'yearly',
        'company_name' => 'HTTP Co',
        'billing_email' => 'http@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/billing/sandbox/');

    $pending = TenantSubscription::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', 'incomplete')
        ->where('replaces_subscription_id', $current->id)
        ->first();

    expect($pending)->not->toBeNull()
        ->and($pending->plan_code)->toBe('growth')
        ->and($current->fresh()->status)->toBe('active');
});

it('keeps the current plan when a plan-change checkout is declined', function () {
    [$tenant, $user, , $growth, $current] = planChangeHttpFixture();

    $this->actingAs($user)->post(route('billing.checkout.store', [$tenant, $growth]), [
        'period' => 'yearly',
        'company_name' => 'HTTP Co',
        'billing_email' => 'http@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ])->assertRedirect();

    $pending = TenantSubscription::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', 'incomplete')
        ->where('replaces_subscription_id', $current->id)
        ->firstOrFail();

    $invoice = $pending->invoices()->latest('id')->firstOrFail();
    app(SandboxPaymentGateway::class)->decline($invoice);

    expect($pending->fresh()->status)->toBe('canceled')
        ->and($current->fresh()->status)->toBe('active')
        ->and($current->fresh()->plan_code)->toBe('starter');
});

it('switches atomically after plan-change payment succeeds via checkout route', function () {
    [$tenant, $user, , $growth, $current] = planChangeHttpFixture();

    $this->actingAs($user)->post(route('billing.checkout.store', [$tenant, $growth]), [
        'period' => 'yearly',
        'company_name' => 'HTTP Co',
        'billing_email' => 'http@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ])->assertRedirect();

    $pending = TenantSubscription::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', 'incomplete')
        ->where('replaces_subscription_id', $current->id)
        ->firstOrFail();

    $invoice = $pending->invoices()->latest('id')->firstOrFail();
    app(SandboxPaymentGateway::class)->approve($invoice);

    expect($pending->fresh()->status)->toBe('active')
        ->and($pending->fresh()->plan_code)->toBe('growth')
        ->and($current->fresh()->status)->toBe('canceled');
});

it('does not blame permissions when the same plan is selected again', function () {
    [$tenant, $user, $starter] = planChangeHttpFixture();

    $this->actingAs($user)
        ->from(route('billing.checkout.show', [$tenant, $starter, 'period' => 'monthly']))
        ->post(route('billing.checkout.store', [$tenant, $starter]), [
            'period' => 'monthly',
            'company_name' => 'HTTP Co',
            'billing_email' => 'http@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'checkout' => BillingCustomerMessages::CHECKOUT_PLAN_UNCHANGED,
        ]);
});

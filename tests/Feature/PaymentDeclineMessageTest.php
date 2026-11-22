<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Support\BillingCustomerMessages;
use Database\Seeders\PlanSeeder;

it('shows a customer-friendly decline message after sandbox decline', function () {
    $this->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Decline Message Tenant',
        'slug' => 'decline-msg-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $plan = Plan::query()->where('code', 'starter')->firstOrFail();

    $result = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', [
        'company_name' => 'Decline Co',
        'billing_email' => 'decline@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);

    $this->actingAs($user)
        ->post(route('billing.sandbox.checkout.decline', [$tenant, $result['invoice']]))
        ->assertRedirect(route('billing.settings.show', $tenant))
        ->assertSessionHasErrors([
            'checkout' => BillingCustomerMessages::PAYMENT_DECLINED,
        ]);

    expect(BillingCustomerMessages::PAYMENT_DECLINED)
        ->not->toContain('webhook')
        ->and(BillingCustomerMessages::PAYMENT_DECLINED)->toContain('declined');
});

it('exposes the same decline copy on checkout return for failed invoices', function () {
    $this->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Return Decline Tenant',
        'slug' => 'return-decline-'.uniqid(),
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
    $plan = Plan::query()->where('code', 'starter')->firstOrFail();

    $result = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', [
        'company_name' => 'Return Co',
        'billing_email' => 'return@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->decline($result['invoice']);

    $this->actingAs($user)
        ->get(route('billing.checkout.return', [$tenant, $result['invoice']->fresh()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/checkout-return')
            ->where('declined', true)
            ->where('declineMessage', BillingCustomerMessages::PAYMENT_DECLINED));
});

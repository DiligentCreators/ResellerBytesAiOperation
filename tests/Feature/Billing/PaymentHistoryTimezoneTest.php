<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use Database\Seeders\PlanSeeder;

it('passes a labelled timezone for payment history rendering', function (): void {
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'TZ Billing',
        'slug' => 'tz-billing-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'America/New_York',
    ]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $plan = Plan::query()->where('code', 'starter')->firstOrFail();
    $checkout = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', [
        'company_name' => 'TZ Co',
        'billing_email' => 'tz@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    $this->actingAs($user)
        ->get(route('billing.settings.show', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/settings')
            ->where('timezone', 'America/New_York')
            ->has('payments.0.received_at'));
});

it('falls back to UTC when the tenant has no timezone', function (): void {
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'UTC Billing',
        'slug' => 'utc-billing-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => null,
    ]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('billing.settings.show', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('timezone', 'UTC'));
});

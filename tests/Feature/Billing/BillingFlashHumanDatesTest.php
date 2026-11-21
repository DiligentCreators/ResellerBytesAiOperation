<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Support\BillingDates;
use Database\Seeders\PlanSeeder;

it('uses a human date without an ISO timestamp in the schedule flash', function (): void {
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Human Date Flash',
        'slug' => 'human-date-flash-'.uniqid(),
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

    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $starter = Plan::query()->where('code', 'starter')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $owner, $growth, 'yearly', [
        'company_name' => 'Flash Co',
        'billing_email' => 'flash@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);
    $current = $checkout['subscription']->fresh();

    $response = $this->actingAs($owner)->post(route('billing.checkout.store', [$tenant, $starter]), [
        'period' => 'monthly',
        'company_name' => 'Flash Co',
        'billing_email' => 'flash@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);

    $when = BillingDates::humanDate($current->fresh()->scheduled_change_at, $tenant);
    $response->assertRedirect(route('billing.settings.show', $tenant))
        ->assertSessionHas('status', 'Plan change scheduled for '.$when.'.');

    expect($when)->toMatch('/^[A-Z][a-z]{2} \d{1,2}, \d{4}$/')
        ->and(session('status'))->not->toMatch('/\d{4}-\d{2}-\d{2}T/');
});

it('keeps other billing status flashes free of ISO timestamps', function (): void {
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Other Flash Dates',
        'slug' => 'other-flash-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'America/Chicago',
    ]);
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $checkout = app(CheckoutService::class)->begin($tenant->id, $owner, $growth, 'monthly', [
        'company_name' => 'Other Co',
        'billing_email' => 'other@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);
    $subscription = $checkout['subscription']->fresh();

    $this->actingAs($owner)
        ->post(route('billing.subscriptions.cancel', [$tenant, $subscription]))
        ->assertRedirect()
        ->assertSessionHas('status', 'Subscription will cancel at period end.');

    expect(session('status'))->not->toMatch('/\d{4}-\d{2}-\d{2}T/');

    $this->actingAs($owner)
        ->post(route('billing.subscriptions.resume', [$tenant, $subscription]))
        ->assertRedirect()
        ->assertSessionHas('status', 'Cancellation at period end cleared.');

    expect(session('status'))->not->toMatch('/\d{4}-\d{2}-\d{2}T/');
});

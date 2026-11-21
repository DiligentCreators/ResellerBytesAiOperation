<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\SubscriptionManagementService;
use App\Support\BillingCustomerMessages;
use App\Support\BillingDates;
use Database\Seeders\PlanSeeder;

function scheduledChangeReplaceFixture(): array
{
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Replace Schedule Tenant',
        'slug' => 'replace-sched-'.uniqid(),
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
    $business = Plan::query()->where('code', 'business')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $owner, $growth, 'yearly', [
        'company_name' => 'Replace Co',
        'billing_email' => 'replace@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    return [$tenant, $owner, $growth, $starter, $business, $checkout['subscription']->fresh()];
}

function scheduleBillingDetails(): array
{
    return [
        'company_name' => 'Replace Co',
        'billing_email' => 'replace@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ];
}

it('exposes the existing pending change on the schedule confirm page', function (): void {
    [$tenant, $owner, , $starter, $business, $current] = scheduledChangeReplaceFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $business,
        'monthly',
        scheduleBillingDetails(),
    );

    $this->actingAs($owner)
        ->get(route('billing.checkout.show', [$tenant, $starter, 'period' => 'monthly']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/checkout')
            ->where('changeMode', 'downgrade')
            ->where('scheduledChange.plan_code', 'business')
            ->where('scheduledChange.plan_name', 'Business')
            ->where('scheduledChange.charge_period', 'monthly')
            ->has('scheduledChange.change_at'));
});

it('replaces a pending scheduled change and flashes that it replaced the earlier target', function (): void {
    [$tenant, $owner, , $starter, $business, $current] = scheduledChangeReplaceFixture();

    // Growth yearly → Business monthly is a scheduled downgrade (yearly→monthly).
    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $business,
        'monthly',
        scheduleBillingDetails(),
    );

    $previousWhen = BillingDates::humanDate($current->fresh()->scheduled_change_at, $tenant);

    $response = $this->actingAs($owner)->post(route('billing.checkout.store', [$tenant, $starter]), [
        'period' => 'monthly',
        ...scheduleBillingDetails(),
    ]);

    $response->assertRedirect(route('billing.settings.show', $tenant));
    $status = session('status');
    expect($status)->toBeString()
        ->and($status)->toContain('replaced the previous change to Business (monthly)')
        ->and($status)->toContain('Starter (monthly)')
        ->and($status)->toContain($previousWhen)
        ->and($status)->not->toMatch('/\d{4}-\d{2}-\d{2}T/');

    expect($current->fresh()->scheduled_plan_id)->toBe($starter->id)
        ->and($current->fresh()->scheduled_charge_period)->toBe('monthly');
});

it('returns a friendly no-op when the same plan and period are already scheduled', function (): void {
    [$tenant, $owner, , $starter, , $current] = scheduledChangeReplaceFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        scheduleBillingDetails(),
    );

    $this->actingAs($owner)
        ->from(route('billing.checkout.show', [$tenant, $starter, 'period' => 'monthly']))
        ->post(route('billing.checkout.store', [$tenant, $starter]), [
            'period' => 'monthly',
            ...scheduleBillingDetails(),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'checkout' => BillingCustomerMessages::CHECKOUT_SCHEDULED_CHANGE_ALREADY,
        ]);

    expect($current->fresh()->scheduled_plan_id)->toBe($starter->id)
        ->and($current->fresh()->scheduled_charge_period)->toBe('monthly');
});

it('keeps the first-time schedule flash without replaced wording', function (): void {
    [$tenant, $owner, , $starter, , $current] = scheduledChangeReplaceFixture();

    $response = $this->actingAs($owner)->post(route('billing.checkout.store', [$tenant, $starter]), [
        'period' => 'monthly',
        ...scheduleBillingDetails(),
    ]);

    $when = BillingDates::humanDate($current->fresh()->scheduled_change_at, $tenant);
    $response->assertRedirect(route('billing.settings.show', $tenant))
        ->assertSessionHas('status', 'Plan change scheduled for '.$when.'.');

    $status = session('status');
    expect($status)->not->toContain('replaced')
        ->and($status)->not->toMatch('/\d{4}-\d{2}-\d{2}T/');
});

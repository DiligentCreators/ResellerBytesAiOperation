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

function sameTargetFixture(): array
{
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Same Target Tenant',
        'slug' => 'same-target-'.uniqid(),
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
        'company_name' => 'Same Target Co',
        'billing_email' => 'same-target@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    return [$tenant, $owner, $starter, $business, $checkout['subscription']->fresh()];
}

function sameTargetBillingDetails(): array
{
    return [
        'company_name' => 'Same Target Co',
        'billing_email' => 'same-target@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ];
}

it('flags the confirm page when the selected plan and period match the pending scheduled change', function (): void {
    [$tenant, $owner, $starter, , $current] = sameTargetFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        sameTargetBillingDetails(),
    );

    $when = BillingDates::humanDate($current->fresh()->scheduled_change_at, $tenant);

    $this->actingAs($owner)
        ->get(route('billing.checkout.show', [$tenant, $starter, 'period' => 'monthly']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/checkout')
            ->where('changeMode', 'downgrade')
            ->where('scheduledChangeIsSameTarget', true)
            ->where('scheduledChangeHumanDate', $when)
            ->where('scheduledChange.plan_code', 'starter')
            ->where('scheduledChange.charge_period', 'monthly'));
});

it('shows the replace notice when the confirm page targets a different scheduled plan', function (): void {
    [$tenant, $owner, $starter, $business, $current] = sameTargetFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $business,
        'monthly',
        sameTargetBillingDetails(),
    );

    $this->actingAs($owner)
        ->get(route('billing.checkout.show', [$tenant, $starter, 'period' => 'monthly']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/checkout')
            ->where('scheduledChangeIsSameTarget', false)
            ->where('scheduledChangeHumanDate', null)
            ->where('scheduledChange.plan_code', 'business')
            ->where('scheduledChange.charge_period', 'monthly'));
});

it('still rejects POST when the same scheduled target is submitted', function (): void {
    [$tenant, $owner, $starter, , $current] = sameTargetFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        sameTargetBillingDetails(),
    );

    $this->actingAs($owner)
        ->from(route('billing.checkout.show', [$tenant, $starter, 'period' => 'monthly']))
        ->post(route('billing.checkout.store', [$tenant, $starter]), [
            'period' => 'monthly',
            ...sameTargetBillingDetails(),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'checkout' => BillingCustomerMessages::CHECKOUT_SCHEDULED_CHANGE_ALREADY,
        ]);
});

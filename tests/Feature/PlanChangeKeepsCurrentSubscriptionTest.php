<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\SubscriptionManagementService;
use Database\Seeders\PlanSeeder;

function planChangeFixture(): array
{
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Plan Change Tenant',
        'slug' => 'plan-change-'.uniqid(),
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
        'company_name' => 'PC Co',
        'billing_email' => 'pc@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    expect($checkout['subscription']->fresh()->status)->toBe('active');

    return [$tenant, $user, $starter, $growth, $checkout['subscription']->fresh()];
}

it('keeps the current subscription active until the plan-change payment webhook succeeds', function () {
    [$tenant, $user, , $growth, $current] = planChangeFixture();

    $change = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $user,
        $growth,
        'monthly',
        [
            'company_name' => 'PC Co',
            'billing_email' => 'pc@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($current->fresh()->status)->toBe('active')
        ->and($change['subscription']->status)->toBe('incomplete')
        ->and($change['subscription']->replaces_subscription_id)->toBe($current->id)
        ->and($change['current_subscription']->status)->toBe('active');

    // Abandoned / declined plan change cancels only the incomplete subscription.
    app(SandboxPaymentGateway::class)->decline($change['invoice']);

    expect($change['subscription']->fresh()->status)->toBe('canceled')
        ->and($current->fresh()->status)->toBe('active');
});

it('switches atomically to the new plan after plan-change payment succeeds', function () {
    [$tenant, $user, , $growth, $current] = planChangeFixture();

    $change = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $user,
        $growth,
        'monthly',
        [
            'company_name' => 'PC Co',
            'billing_email' => 'pc@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    app(SandboxPaymentGateway::class)->approve($change['invoice']);

    expect($change['subscription']->fresh()->status)->toBe('active')
        ->and($change['subscription']->fresh()->plan_code)->toBe('growth')
        ->and($current->fresh()->status)->toBe('canceled');

    $currentCount = TenantSubscription::query()
        ->where('tenant_id', $tenant->id)
        ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
        ->count();

    expect($currentCount)->toBe(1);
});

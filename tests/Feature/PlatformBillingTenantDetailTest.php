<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\CentralStaffAccess;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\SubscriptionManagementService;
use Database\Seeders\PlanSeeder;

function platformBillingTenantFixture(): array
{
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $granter = User::factory()->create(['email_verified_at' => now()]);
    $staff = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['billing'],
        'granted_by_user_id' => $granter->id,
    ]);

    $tenant = Tenant::create([
        'name' => 'Platform Billing Detail',
        'slug' => 'platform-billing-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
        'settings' => [
            'billing' => [
                'company_name' => 'Acme Ops',
                'billing_email' => 'billing@acme.test',
                'country' => 'US',
                'address_line1' => '100 Market St',
                'address_line2' => 'Suite 4',
                'city' => 'San Francisco',
                'postal_code' => '94105',
                'tax_id' => 'US-123',
            ],
        ],
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
        'company_name' => 'Acme Ops',
        'billing_email' => 'billing@acme.test',
        'country' => 'US',
        'address_line1' => '100 Market St',
        'address_line2' => 'Suite 4',
        'city' => 'San Francisco',
        'postal_code' => '94105',
        'tax_id' => 'US-123',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    $tenant->refresh();

    return [$staff, $tenant, $owner, $growth, $starter, $checkout['subscription']->fresh()];
}

it('passes structured billing fields price interval and null scheduled change on admin tenant detail', function (): void {
    [$staff, $tenant] = platformBillingTenantFixture();

    $this->actingAs($staff)
        ->get(route('platform.billing.tenant', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/tenant')
            ->where('billingDetails.company_name', 'Acme Ops')
            ->where('billingDetails.billing_email', 'billing@acme.test')
            ->where('billingDetails.country', 'US')
            ->where('billingDetails.address_line1', '100 Market St')
            ->where('billingDetails.city', 'San Francisco')
            ->where('billingDetails.postal_code', '94105')
            ->where('billingDetails.tax_id', 'US-123')
            ->where('subscription.plan_name', 'Growth')
            ->where('subscription.billing_period', 'yearly')
            ->where('subscription.cancel_at_period_end', false)
            ->where('subscription.billing_provider', 'sandbox')
            ->where('subscription.billing_mode', 'automated')
            ->has('subscription.unit_amount_minor')
            ->has('subscription.current_period_starts_at')
            ->has('subscription.current_period_ends_at')
            ->where('scheduledChange', null)
            ->missing('tenant.billing'));
});

it('includes the pending scheduled change on admin tenant detail', function (): void {
    [$staff, $tenant, $owner, , $starter, $current] = platformBillingTenantFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        [
            'company_name' => 'Acme Ops',
            'billing_email' => 'billing@acme.test',
            'country' => 'US',
            'address_line1' => '100 Market St',
        ],
    );

    $this->actingAs($staff)
        ->get(route('platform.billing.tenant', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/tenant')
            ->where('scheduledChange.plan_code', 'starter')
            ->where('scheduledChange.plan_name', 'Starter')
            ->where('scheduledChange.charge_period', 'monthly')
            ->has('scheduledChange.change_at'));
});

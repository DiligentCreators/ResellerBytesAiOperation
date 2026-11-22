<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\SubscriptionManagementService;
use Database\Seeders\PlanSeeder;

function billingAuthzFixture(): array
{
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Authz Billing',
        'slug' => 'authz-billing-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $outsider = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $starter = Plan::query()->where('code', 'starter')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $owner, $growth, 'yearly', [
        'company_name' => 'Authz Co',
        'billing_email' => 'authz@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $checkout['subscription']->id,
        $owner,
        $starter,
        'monthly',
        [
            'company_name' => 'Authz Co',
            'billing_email' => 'authz@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    return [$tenant, $owner, $outsider, $growth, $checkout['subscription']->fresh()];
}

it('returns 404 for outsiders updating billing details with an invalid payload', function (): void {
    [$tenant, , $outsider] = billingAuthzFixture();

    $this->actingAs($outsider)
        ->put(route('billing.settings.details', $tenant), [])
        ->assertNotFound();
});

it('returns 422 for members updating billing details with an invalid payload', function (): void {
    [$tenant, $owner] = billingAuthzFixture();

    $this->actingAs($owner)
        ->from(route('billing.settings.show', $tenant))
        ->put(route('billing.settings.details', $tenant), [])
        ->assertRedirect()
        ->assertSessionHasErrors(['company_name', 'billing_email', 'country', 'address_line1']);
});

it('returns 404 for outsiders posting checkout with an invalid payload', function (): void {
    [$tenant, , $outsider, $growth] = billingAuthzFixture();

    $this->actingAs($outsider)
        ->post(route('billing.checkout.store', [$tenant, $growth]), [])
        ->assertNotFound();
});

it('returns 422 for members posting checkout with an invalid payload', function (): void {
    [$tenant, $owner, , $growth] = billingAuthzFixture();

    $this->actingAs($owner)
        ->from(route('billing.checkout.show', [$tenant, $growth]))
        ->post(route('billing.checkout.store', [$tenant, $growth]), [])
        ->assertRedirect()
        ->assertSessionHasErrors(['period', 'company_name', 'billing_email', 'country', 'address_line1']);
});

it('returns 404 for outsiders canceling a scheduled change even with no body', function (): void {
    [$tenant, , $outsider, , $subscription] = billingAuthzFixture();

    $this->actingAs($outsider)
        ->delete(route('billing.subscriptions.scheduled-change.cancel', [$tenant, $subscription]))
        ->assertNotFound();
});

it('returns 422-equivalent redirect errors only for members on plan-change checkout invalid payloads', function (): void {
    // Covered by the checkout store member 422 case above; plan changes share that route.
    [$tenant, $owner, , $growth] = billingAuthzFixture();

    $this->actingAs($owner)
        ->post(route('billing.checkout.store', [$tenant, $growth]), [
            'period' => 'not-a-period',
        ])
        ->assertSessionHasErrors(['period']);
});

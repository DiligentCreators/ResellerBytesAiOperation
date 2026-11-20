<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Tenancy\TenantBillingWebhookInbox;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;

function billingCheckoutFixture(string $role = TenantMembership::ROLE_OWNER): array
{
    test()->seed(PlanSeeder::class);

    $tenant = Tenant::create([
        'name' => 'Checkout Tenant',
        'slug' => 'checkout-tenant-'.strtolower($role).'-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => $role,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $plan = Plan::query()->where('code', 'starter')->firstOrFail();

    return [$tenant, $user, $plan];
}

function checkoutBillingDetails(): array
{
    return [
        'company_name' => 'Checkout Co',
        'billing_email' => 'billing@example.test',
        'country' => 'US',
        'address_line1' => '1 Main St',
        'city' => 'Austin',
        'state' => 'TX',
        'postal_code' => '78701',
        'tax_id' => null,
    ];
}

it('forbids checkout for non-billing roles', function () {
    [$tenant, $user, $plan] = billingCheckoutFixture(TenantMembership::ROLE_VIEWER);

    $this->actingAs($user)
        ->get(route('billing.checkout.show', [$tenant, $plan]))
        ->assertForbidden();
});

it('forbids checkout against another tenant', function () {
    [$tenantA, $userA, $plan] = billingCheckoutFixture();
    [$tenantB] = billingCheckoutFixture();

    $this->actingAs($userA)
        ->get(route('billing.checkout.show', [$tenantB, $plan]))
        ->assertNotFound();
});

it('approves sandbox checkout through the webhook path and activates the subscription', function () {
    [$tenant, $user, $plan] = billingCheckoutFixture();

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        checkoutBillingDetails(),
    );

    $invoice = $result['invoice'];
    expect($invoice->status)->toBe('open')
        ->and($result['subscription']->status)->toBe('trialing');

    app(SandboxPaymentGateway::class)->approve($invoice);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->amount_paid_minor)->toBe($invoice->amount_due_minor)
        ->and($result['subscription']->fresh()->status)->toBe('active')
        ->and($invoice->payments()->where('status', 'succeeded')->count())->toBe(1);
});

it('marks declined sandbox checkout as failed and cancels the provisional subscription', function () {
    [$tenant, $user, $plan] = billingCheckoutFixture();

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        checkoutBillingDetails(),
    );

    app(SandboxPaymentGateway::class)->decline($result['invoice']);

    expect($result['invoice']->fresh()->status)->toBe('failed')
        ->and($result['subscription']->fresh()->status)->toBe('canceled');
});

it('is idempotent for duplicate sandbox payment events', function () {
    [$tenant, $user, $plan] = billingCheckoutFixture();
    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        checkoutBillingDetails(),
    );

    $invoice = $result['invoice'];
    $payload = [
        'invoice_id' => $invoice->id,
        'payment_id' => 'pay_idempotent_duplicate',
        'amount_minor' => $invoice->amount_due_minor,
        'currency' => $invoice->currency,
    ];

    $inbox = app(TenantBillingWebhookInbox::class);
    $first = $inbox->acceptVerifiedEvent(
        $tenant->id,
        'sandbox',
        'evt_idempotent_checkout_1',
        'invoice.payment_succeeded',
        $payload,
        now('UTC'),
        $invoice->provider_account_id,
    );
    $duplicate = $inbox->acceptVerifiedEvent(
        $tenant->id,
        'sandbox',
        'evt_idempotent_checkout_1',
        'invoice.payment_succeeded',
        $payload,
        now('UTC'),
        $invoice->provider_account_id,
    );

    expect($duplicate->id)->toBe($first->id);

    Artisan::call('billing:webhooks:reconcile', ['--limit' => 20]);
    Artisan::call('billing:webhooks:reconcile', ['--limit' => 20]);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->payments()->count())->toBe(1)
        ->and($result['subscription']->fresh()->status)->toBe('active');
});

it('assigns sequential platform invoice numbers', function () {
    [$tenant, $user, $plan] = billingCheckoutFixture();

    $first = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', checkoutBillingDetails());
    TenantSubscription::query()->whereKey($first['subscription']->id)->update([
        'status' => 'canceled',
        'canceled_at' => now(),
    ]);
    // Allow a second current subscription by canceling first (unique index on sqlite/pgsql).
    $other = Tenant::create([
        'name' => 'Second Checkout',
        'slug' => 'second-checkout-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $other->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $second = app(CheckoutService::class)->begin($other->id, $user, $plan, 'monthly', checkoutBillingDetails());

    expect($first['invoice']->invoice_number)->toMatch('/^RB-\d{4}-\d{6}$/')
        ->and($second['invoice']->invoice_number)->toMatch('/^RB-\d{4}-\d{6}$/')
        ->and($second['invoice']->invoice_number)->not->toBe($first['invoice']->invoice_number);
});

it('rejects checkout begin from roles without billing.manage', function () {
    [$tenant, $user, $plan] = billingCheckoutFixture(TenantMembership::ROLE_ADMIN);

    expect(fn () => app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        checkoutBillingDetails(),
    ))->toThrow(AuthorizationException::class);
});

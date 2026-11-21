<?php

use App\Billing\Kelviq\KelviqPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentGatewayManager;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function kelviqGatewayFixture(): array
{
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'kelviq');
    config()->set('billing.kelviq.mode', 'sandbox');
    config()->set('billing.kelviq.api_key', 'kq_test_key');
    config()->set('billing.kelviq.webhook_secret', 'kq_whsec_test');

    $tenant = Tenant::create([
        'name' => 'Kelviq Gateway Tenant',
        'slug' => 'kelviq-gw-'.uniqid(),
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
    $plan->forceFill([
        'kelviq_plan_identifier_monthly' => 'starter-monthly',
        'kelviq_plan_identifier_yearly' => 'starter-yearly',
    ])->save();

    return [$tenant, $user, $plan];
}

function kelviqBillingDetails(): array
{
    return [
        'company_name' => 'Kelviq Co',
        'billing_email' => 'billing@kelviq-test.test',
        'country' => 'US',
        'address_line1' => '1 Main St',
        'city' => 'Austin',
        'state' => 'TX',
        'postal_code' => '78701',
        'tax_id' => null,
    ];
}

it('builds the documented Kelviq checkout session request shape', function () {
    [$tenant, $user, $plan] = kelviqGatewayFixture();
    Http::preventStrayRequests();
    Http::fake([
        'https://sandboxapi.kelviq.com/api/v1/customers/*' => Http::response(['customerId' => 'acct_kelviq_x'], 201),
        'https://sandboxapi.kelviq.com/api/v1/checkout/*' => Http::response([
            'checkoutUrl' => 'https://www.kelviq.com/checkout/cs_test/',
            'checkoutSessionId' => 'cs_test_abc',
        ], 200),
    ]);

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        kelviqBillingDetails(),
    );

    expect($result['checkout_url'])->toBe('https://www.kelviq.com/checkout/cs_test/')
        ->and($result['subscription']->billing_provider)->toBe('kelviq')
        ->and($result['subscription']->provider_subscription_id)->toBeNull()
        ->and($result['subscription']->status)->toBe('incomplete');

    Http::assertSent(function ($request) use ($tenant, $plan) {
        if (! str_ends_with($request->url(), '/checkout/')) {
            return false;
        }

        $body = $request->data();

        return ($body['planIdentifier'] ?? null) === 'starter-monthly'
            && ($body['chargePeriod'] ?? null) === 'MONTHLY'
            && ($body['customerId'] ?? null) === 'acct_kelviq_'.str_replace('-', '', $tenant->id)
            && ($body['metadata']['tenant_id'] ?? null) === $tenant->id
            && ($body['metadata']['plan'] ?? null) === $plan->code
            && is_string($body['successUrl'] ?? null)
            && is_string($body['cancelUrl'] ?? null);
    });
});

it('refuses live mode checkout without key and webhook secret', function () {
    config()->set('billing.default_gateway', 'kelviq');
    config()->set('billing.kelviq.mode', 'live');
    config()->set('billing.kelviq.api_key', '');
    config()->set('billing.kelviq.webhook_secret', '');

    expect(fn () => app(PaymentGatewayManager::class)->driver('kelviq'))
        ->toThrow(RuntimeException::class, 'billing_kelviq_live_credentials_required');
});

it('does not leak kelviq secrets into logs during checkout', function () {
    [$tenant, $user, $plan] = kelviqGatewayFixture();
    config()->set('billing.kelviq.api_key', 'kq_secret_should_not_log');
    config()->set('billing.kelviq.webhook_secret', 'kq_whsec_should_not_log');

    Http::preventStrayRequests();
    Http::fake([
        'https://sandboxapi.kelviq.com/api/v1/customers/*' => Http::response([], 409),
        'https://sandboxapi.kelviq.com/api/v1/checkout/*' => Http::response([
            'checkoutUrl' => 'https://www.kelviq.com/checkout/cs_test/',
            'checkoutSessionId' => 'cs_test_abc',
        ], 200),
    ]);

    Log::spy();

    app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', kelviqBillingDetails());

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return ! str_contains($encoded, 'kq_secret_should_not_log')
            && ! str_contains($encoded, 'kq_whsec_should_not_log');
    })->atLeast()->once();
});

it('exposes kelviq as a payment gateway driver', function () {
    config()->set('billing.kelviq.mode', 'sandbox');
    config()->set('billing.kelviq.api_key', 'kq_test');
    config()->set('billing.kelviq.webhook_secret', 'kq_whsec_test');

    $gateway = app(PaymentGatewayManager::class)->driver('kelviq');

    expect($gateway)->toBeInstanceOf(KelviqPaymentGateway::class)
        ->and($gateway->driver())->toBe('kelviq');
});

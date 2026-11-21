<?php

use App\Jobs\ProcessBillingWebhookReconcile;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingWebhookEvent;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function kelviqWebhookSign(string $body, string $id, string $timestamp, string $secret): string
{
    return 'v1,'.hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $secret);
}

function kelviqWebhookFixture(): array
{
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'kelviq');
    config()->set('billing.kelviq.mode', 'sandbox');
    config()->set('billing.kelviq.api_key', 'kq_test_key');
    config()->set('billing.kelviq.webhook_secret', 'kq_whsec_test_secret');

    $tenant = Tenant::create([
        'name' => 'Kelviq Webhook Tenant',
        'slug' => 'kelviq-wh-'.uniqid(),
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

    Http::fake([
        'https://sandboxapi.kelviq.com/api/v1/customers/*' => Http::response(['ok' => true], 201),
        'https://sandboxapi.kelviq.com/api/v1/checkout/*' => Http::response([
            'checkoutUrl' => 'https://www.kelviq.com/checkout/cs_test/',
            'checkoutSessionId' => 'cs_test_abc',
        ], 200),
    ]);

    $result = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', [
        'company_name' => 'WH Co',
        'billing_email' => 'wh@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);

    $accountId = $result['subscription']->provider_account_id;

    return [$tenant, $user, $plan, $result['subscription'], $result['invoice'], $accountId];
}

function postKelviqWebhook(array $payload, ?string $timestamp = null, ?string $signature = null, ?string $eventId = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $eventId ??= (string) $payload['id'];
    $timestamp ??= (string) now('UTC')->timestamp;
    $secret = (string) config('billing.kelviq.webhook_secret');
    $signature ??= kelviqWebhookSign($body, $eventId, $timestamp, $secret);

    return test()->call(
        'POST',
        route('webhooks.kelviq'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $eventId,
            'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
        ],
        $body,
    );
}

it('rejects invalid kelviq signatures with 4xx', function () {
    [, , , , , $accountId] = kelviqWebhookFixture();
    Queue::fake();

    $payload = [
        'id' => '11111111-1111-1111-1111-111111111111',
        'type' => 'invoice.paid',
        'created_at' => now('UTC')->toIso8601String(),
        'data' => ['object' => [
            'id' => 'INV-1',
            'amount_total_units' => 1000,
            'currency' => 'USD',
            'customer' => ['customer_id' => $accountId],
            'subscription_id' => null,
        ]],
    ];

    postKelviqWebhook($payload, signature: 'v1,'.str_repeat('ab', 32))
        ->assertStatus(400);

    expect(TenantBillingWebhookEvent::query()->count())->toBe(0);
});

it('rejects stale kelviq webhook timestamps', function () {
    [, , , , , $accountId] = kelviqWebhookFixture();
    Queue::fake();

    $payload = [
        'id' => '22222222-2222-2222-2222-222222222222',
        'type' => 'checkout.completed',
        'created_at' => now('UTC')->toIso8601String(),
        'data' => ['object' => [
            'id' => 'cs_1',
            'customer' => ['customer_id' => $accountId],
            'metadata' => [],
        ]],
    ];

    $stale = (string) now('UTC')->subMinutes(10)->timestamp;
    postKelviqWebhook($payload, timestamp: $stale)->assertStatus(400);
});

it('accepts a valid signature, stores idempotently, and queues reconcile', function () {
    [$tenant, , , $subscription, $invoice, $accountId] = kelviqWebhookFixture();
    Queue::fake();

    $subscription->forceFill(['provider_subscription_id' => 'sub-kelviq-1'])->save();

    $payload = [
        'id' => '33333333-3333-3333-3333-333333333333',
        'type' => 'invoice.paid',
        'created_at' => now('UTC')->toIso8601String(),
        'data' => ['object' => [
            'id' => 'INV-PAID-1',
            'amount_total_units' => $invoice->amount_due_minor,
            'currency' => $invoice->currency,
            'customer' => ['customer_id' => $accountId],
            'subscription_id' => 'sub-kelviq-1',
        ]],
    ];

    postKelviqWebhook($payload)->assertOk();
    postKelviqWebhook($payload)->assertOk();

    expect(TenantBillingWebhookEvent::query()->where('provider', 'kelviq')->count())->toBe(1);
    Queue::assertPushed(ProcessBillingWebhookReconcile::class);
});

it('maps invoice.paid through reconcile to activate the subscription', function () {
    [$tenant, , , $subscription, $invoice, $accountId] = kelviqWebhookFixture();

    $subscription->forceFill(['provider_subscription_id' => 'sub-kelviq-activate'])->save();

    $payload = [
        'id' => '44444444-4444-4444-4444-444444444444',
        'type' => 'invoice.paid',
        'created_at' => now('UTC')->toIso8601String(),
        'data' => ['object' => [
            'id' => 'INV-PAID-2',
            'amount_total_units' => $invoice->amount_due_minor,
            'currency' => $invoice->currency,
            'customer' => ['customer_id' => $accountId],
            'subscription_id' => 'sub-kelviq-activate',
        ]],
    ];

    postKelviqWebhook($payload)->assertOk();
    (new ProcessBillingWebhookReconcile)->handle();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($subscription->fresh()->status)->toBe('active');
});

it('stores unknown kelviq events as ignored', function () {
    [, , , , , $accountId] = kelviqWebhookFixture();
    Queue::fake();

    $payload = [
        'id' => '55555555-5555-5555-5555-555555555555',
        'type' => 'feature.usage_alert',
        'created_at' => now('UTC')->toIso8601String(),
        'data' => ['object' => [
            'id' => 'feat-1',
            'customer' => ['customer_id' => $accountId],
        ]],
    ];

    postKelviqWebhook($payload)->assertOk();

    $event = TenantBillingWebhookEvent::query()->where('provider_event_id', $payload['id'])->first();
    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe('webhook.ignored');
});

it('mirrors invoice.created onto the local invoice', function () {
    [, , , $subscription, $invoice, $accountId] = kelviqWebhookFixture();
    $subscription->forceFill(['provider_subscription_id' => 'sub-kelviq-mirror'])->save();

    $payload = [
        'id' => '66666666-6666-6666-6666-666666666666',
        'type' => 'invoice.created',
        'created_at' => now('UTC')->toIso8601String(),
        'data' => ['object' => [
            'id' => 'INV-REMOTE-9',
            'status' => 'OPEN',
            'amount_total_units' => $invoice->amount_due_minor,
            'currency' => $invoice->currency,
            'payment_link' => 'https://portal.kelviq.com/pay/abc',
            'customer' => ['customer_id' => $accountId],
            'subscription_id' => 'sub-kelviq-mirror',
        ]],
    ];

    postKelviqWebhook($payload)->assertOk();
    (new ProcessBillingWebhookReconcile)->handle();

    expect($invoice->fresh()->provider_remote_invoice_id)->toBe('INV-REMOTE-9')
        ->and($invoice->fresh()->payment_link)->toBe('https://portal.kelviq.com/pay/abc')
        ->and($invoice->fresh()->provider_remote_status)->toBe('OPEN');
});

it('rejects unsigned empty verify probes with 4xx', function () {
    $this->post(route('webhooks.kelviq'))->assertStatus(400);
});

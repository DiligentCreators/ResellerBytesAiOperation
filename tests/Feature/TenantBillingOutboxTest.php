<?php

use App\\Events\\TenantBillingOutboxPublished;
use App\\Models\\Tenant;
use App\\Models\\TenantBillingInvoice;
use App\\Models\\TenantBillingOutboxMessage;
use App\\Models\\TenantBillingWebhookEvent;
use App\\Models\\TenantSubscription;
use App\\Services\\Tenancy\\TenantBillingOutbox;
use App\\Services\\Tenancy\\TenantBillingLedgerService;
use App\\Services\\Tenancy\\TenantBillingWebhookInbox;
use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Event;
use Illuminate\\Database\\QueryException;
use InvalidArgumentException;
use RuntimeException;

function createBillingOutboxTenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'status' => Tenant::STATUS_ACTIVE,
    ]);
}

function createBillingOutboxInvoice(Tenant $tenant, int $amount = 4900): TenantBillingInvoice
{
    $subscription = TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'outbox-test',
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => $amount,
        'entitlements' => ['diagnostics.analysis' => true],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    return TenantBillingInvoice::create([
        'tenant_id' => $tenant->id,
        'tenant_subscription_id' => $subscription->id,
        'invoice_number' => 'OUTBOX-'.strtoupper(substr(str_replace('-', '', $tenant->id), 0, 12)),
        'status' => 'open',
        'currency' => 'USD',
        'amount_due_minor' => $amount,
        'amount_paid_minor' => 0,
        'issued_at' => now(),
    ]);
}

it('requires a surrounding transaction and deduplicates identical outbox events per tenant', function () {
    $tenant = createBillingOutboxTenant('billing-outbox-transaction');
    $outbox = app(TenantBillingOutbox::class);

    expect(fn () => $outbox->enqueue($tenant->id, 'invoice-issued:1', 'billing.invoice.issued', 'billing_invoice', 1, ['invoice_id' => 1]))
        ->toThrow(LogicException::class, 'billing_outbox_requires_business_transaction');

    $first = DB::transaction(fn () => $outbox->enqueue(
        $tenant->id,
        'invoice-issued:1',
        'billing.invoice.issued',
        'billing_invoice',
        1,
        ['invoice_id' => 1, 'currency' => 'USD'],
    ));
    $second = DB::transaction(fn () => $outbox->enqueue(
        $tenant->id,
        'invoice-issued:1',
        'billing.invoice.issued',
        'billing_invoice',
        1,
        ['currency' => 'USD', 'invoice_id' => 1],
    ));

    expect($second->id)->toBe($first->id)
        ->and(TenantBillingOutboxMessage::query()->where('tenant_id', $tenant->id)->count())->toBe(1);

    expect(fn () => DB::transaction(fn () => $outbox->enqueue(
        $tenant->id,
        'invoice-issued:1',
        'billing.invoice.issued',
        'billing_invoice',
        1,
        ['invoice_id' => 2],
    )))->toThrow(RuntimeException::class, 'billing_outbox_idempotency_conflict');
});

it('accepts a verified webhook once and rejects a changed replay payload', function () {
    $tenant = createBillingOutboxTenant('billing-webhook-idempotency');
    $inbox = app(TenantBillingWebhookInbox::class);
    $payload = ['invoice_id' => 44, 'payment_id' => 'pay_44', 'amount_minor' => 4900, 'currency' => 'USD'];

    $first = $inbox->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_44',
        'invoice.payment_succeeded',
        $payload,
        now('UTC'),
        'acct_tenant_44',
    );
    $again = $inbox->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_44',
        'invoice.payment_succeeded',
        ['currency' => 'USD', 'amount_minor' => 4900, 'payment_id' => 'pay_44', 'invoice_id' => 44],
        now('UTC'),
        'acct_tenant_44',
    );

    expect($again->id)->toBe($first->id)
        ->and(TenantBillingWebhookEvent::query()->where('tenant_id', $tenant->id)->count())->toBe(1);

    expect(fn () => $inbox->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_44',
        'invoice.payment_succeeded',
        ['invoice_id' => 44, 'payment_id' => 'pay_44', 'amount_minor' => 5000, 'currency' => 'USD'],
        now('UTC'),
        'acct_tenant_44',
    ))->toThrow(RuntimeException::class, 'billing_webhook_idempotency_conflict');
});

it('rejects unrecognized webhook payload fields before persistence', function () {
    $tenant = createBillingOutboxTenant('billing-webhook-payload-allowlist');

    expect(fn () => app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_untrusted',
        'invoice.payment_succeeded',
        [
            'invoice_id' => 1,
            'payment_id' => 'pay_1',
            'amount_minor' => 100,
            'currency' => 'USD',
            'secret' => 'must-not-be-stored',
        ],
        now('UTC'),
        'acct_allowlist',
    ))->toThrow(InvalidArgumentException::class, 'billing_webhook_payload_fields_invalid');

    expect(TenantBillingWebhookEvent::query()->count())->toBe(0);
});

it('reconciles a verified payment exactly once and atomically creates its outbound event', function () {
    $tenant = createBillingOutboxTenant('billing-webhook-reconcile');
    $invoice = createBillingOutboxInvoice($tenant, 4900);
    $event = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_payment_success_001',
        'invoice.payment_succeeded',
        [
            'invoice_id' => $invoice->id,
            'payment_id' => 'pay_success_001',
            'amount_minor' => 4900,
            'currency' => 'USD',
        ],
        now('UTC'),
        'acct_reconcile_001',
    );

    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->amount_paid_minor)->toBe(4900)
        ->and($invoice->payments()->count())->toBe(1)
        ->and(TenantBillingOutboxMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'billing.payment.recorded')
            ->count())->toBe(1);

    Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]);

    expect($invoice->payments()->count())->toBe(1)
        ->and($invoice->fresh()->amount_paid_minor)->toBe(4900);
});

it('dispatches outbox records and records delivery state', function () {
    Event::fake();
    $tenant = createBillingOutboxTenant('billing-outbox-dispatch');
    $message = DB::transaction(fn () => app(TenantBillingOutbox::class)->enqueue(
        $tenant->id,
        'invoice-issued:dispatch',
        'billing.invoice.issued',
        'billing_invoice',
        77,
        ['invoice_id' => 77],
    ));

    expect(Artisan::call('billing:outbox:dispatch', ['--limit' => 10]))->toBe(0);

    Event::assertDispatched(TenantBillingOutboxPublished::class, fn (TenantBillingOutboxPublished $event): bool => $event->outboxId === $message->id && $event->tenantId === $tenant->id);

    expect($message->fresh()->status)->toBe('delivered')
        ->and($message->fresh()->attempts)->toBe(1)
        ->and($message->fresh()->delivered_at)->not->toBeNull();
});

it('does not accept the same provider account event under a different tenant', function () {
    $tenantA = createBillingOutboxTenant('billing-webhook-account-a');
    $tenantB = createBillingOutboxTenant('billing-webhook-account-b');
    $payload = ['invoice_id' => 1, 'payment_id' => 'pay_account_bound', 'amount_minor' => 100, 'currency' => 'USD'];
    $inbox = app(TenantBillingWebhookInbox::class);

    $inbox->acceptVerifiedEvent($tenantA->id, 'approved_gateway', 'evt_account_bound', 'invoice.payment_succeeded', $payload, now('UTC'), 'acct_shared');

    expect(fn () => $inbox->acceptVerifiedEvent(
        $tenantB->id,
        'approved_gateway',
        'evt_account_bound',
        'invoice.payment_succeeded',
        $payload,
        now('UTC'),
        'acct_shared',
    ))->toThrow(QueryException::class);
});

it('retries transient webhook reconciliation failures with a bounded delay', function () {
    $tenant = createBillingOutboxTenant('billing-webhook-retry');
    $event = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_retry_001',
        'invoice.payment_succeeded',
        ['invoice_id' => 9001, 'payment_id' => 'pay_retry_001', 'amount_minor' => 100, 'currency' => 'USD'],
        now('UTC'),
        'acct_retry_001',
    );

    $ledger = Mockery::mock(TenantBillingLedgerService::class);
    $ledger->shouldReceive('recordConfirmedPayment')->once()->andThrow(new RuntimeException('provider-secret-must-not-be-logged'));
    app()->instance(TenantBillingLedgerService::class, $ledger);

    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    expect($event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->attempts)->toBe(1)
        ->and($event->fresh()->available_at->isFuture())->toBeTrue()
        ->and($event->fresh()->last_error_code)->toBe('billing_webhook_processing_failed');
});

it('dead-letters webhook events after the maximum retry attempt', function () {
    $tenant = createBillingOutboxTenant('billing-webhook-dead-letter');
    $event = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_dead_letter_001',
        'invoice.payment_succeeded',
        ['invoice_id' => 9002, 'payment_id' => 'pay_dead_letter_001', 'amount_minor' => 100, 'currency' => 'USD'],
        now('UTC'),
        'acct_dead_letter_001',
    );
    $event->forceFill(['attempts' => 11])->save();

    $ledger = Mockery::mock(TenantBillingLedgerService::class);
    $ledger->shouldReceive('recordConfirmedPayment')->once()->andThrow(new RuntimeException('transient failure'));
    app()->instance(TenantBillingLedgerService::class, $ledger);

    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    expect($event->fresh()->status)->toBe('dead_lettered')
        ->and($event->fresh()->attempts)->toBe(12)
        ->and($event->fresh()->dead_lettered_at)->not->toBeNull();
});

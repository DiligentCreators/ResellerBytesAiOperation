<?php

use App\\Events\\TenantBillingOutboxPublished;
use App\\Services\\Audit\\AuditEventWriter;
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

function createBillingOutboxInvoice(Tenant $tenant, int $amount = 4900, ?string $providerAccountId = null): TenantBillingInvoice
{
    $subscription = TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'outbox-test',
        'billing_mode' => 'automated',
        'status' => 'active',
        'billing_provider' => $providerAccountId === null ? null : 'approved_gateway',
        'provider_account_id' => $providerAccountId,
        'provider_subscription_id' => $providerAccountId === null ? null : 'provider-subscription-'.$tenant->slug,
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
        'provider' => $providerAccountId === null ? null : 'approved_gateway',
        'provider_account_id' => $providerAccountId,
        'provider_invoice_id' => $providerAccountId === null ? null : 'provider-invoice-'.$tenant->slug,
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
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_reconcile_001');
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
    ))->toThrow(RuntimeException::class, 'billing_webhook_idempotency_conflict');
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

it('keeps tenant billing event identity and payload immutable at the database boundary', function () {
    $tenantA = createBillingOutboxTenant('billing-event-immutable-a');
    $tenantB = createBillingOutboxTenant('billing-event-immutable-b');
    $outbox = DB::transaction(fn () => app(TenantBillingOutbox::class)->enqueue(
        $tenantA->id,
        'immutable-event-1',
        'billing.invoice.issued',
        'billing_invoice',
        101,
        ['invoice_id' => 101],
    ));
    $webhook = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenantA->id,
        'approved_gateway',
        'evt_immutable_1',
        'invoice.payment_succeeded',
        ['invoice_id' => 101, 'payment_id' => 'pay_immutable_1', 'amount_minor' => 100, 'currency' => 'USD'],
        now('UTC'),
        'acct_immutable_1',
    );

    expect(fn () => DB::table('tenant_billing_outbox')->where('id', $outbox->id)->update(['tenant_id' => $tenantB->id]))
        ->toThrow(\\Illuminate\\Database\\QueryException::class);
    expect(fn () => DB::table('tenant_billing_outbox')->where('id', $outbox->id)->update(['payload' => json_encode(['invoice_id' => 999])]))
        ->toThrow(\\Illuminate\\Database\\QueryException::class);
    expect(fn () => DB::table('tenant_billing_webhook_events')->where('id', $webhook->id)->update(['tenant_id' => $tenantB->id]))
        ->toThrow(\\Illuminate\\Database\\QueryException::class);
    expect(fn () => DB::table('tenant_billing_webhook_events')->where('id', $webhook->id)->update(['payload_sha256' => str_repeat('0', 64)]))
        ->toThrow(\\Illuminate\\Database\\QueryException::class);

    DB::table('tenant_billing_outbox')->where('id', $outbox->id)->update(['attempts' => 1, 'status' => 'processing']);
    DB::table('tenant_billing_webhook_events')->where('id', $webhook->id)->update(['attempts' => 1, 'status' => 'processing']);

    expect($outbox->fresh()->attempts)->toBe(1)
        ->and($webhook->fresh()->attempts)->toBe(1);
});

it('binds webhook receipt audit evidence to the webhook tenant without retaining payload data', function () {
    $tenantA = createBillingOutboxTenant('billing-webhook-audit-a');
    $tenantB = createBillingOutboxTenant('billing-webhook-audit-b');
    $event = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenantA->id,
        'approved_gateway',
        'evt_audit_001',
        'invoice.payment_succeeded',
        ['invoice_id' => 202, 'payment_id' => 'pay_audit_001', 'amount_minor' => 200, 'currency' => 'USD'],
        now('UTC'),
        'acct_audit_001',
    );

    $audit = DB::table('audit_events')
        ->where('subject_type', 'tenant_billing_webhook_event')
        ->where('subject_id', (string) $event->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->tenant_id)->toBe($tenantA->id)
        ->and(json_decode($audit->metadata, true))->toMatchArray([
            'provider' => 'approved_gateway',
            'event_type' => 'invoice.payment_succeeded',
            'payload_sha256' => $event->payload_sha256,
        ]);

    expect(fn () => app(AuditEventWriter::class)->append([
        'actor_type' => 'system',
        'actor_id' => null,
        'tenant_id' => $tenantB->id,
        'event_type' => 'tenant_billing.webhook_received',
        'subject_type' => 'tenant_billing_webhook_event',
        'subject_id' => (string) $event->id,
        'metadata' => ['provider' => 'approved_gateway'],
        'occurred_at' => now('UTC'),
    ]))->toThrow(\\Illuminate\\Database\\QueryException::class);
});

it('verifies and maps provider webhooks through the provider-neutral gateway', function () {
    $tenant = createBillingOutboxTenant('billing-provider-gateway');
    DB::table('tenant_billing_provider_accounts')->insert([
        'tenant_id' => $tenant->id,
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_gateway_001',
        'status' => 'active',
        'verified_at' => now('UTC'),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_gateway_001');

    $adapter = new class($invoice->provider_invoice_id) implements \\App\\Contracts\\Billing\\BillingProviderAdapter {
        public function __construct(private readonly string $providerInvoiceId) {}

        public function provider(): string
        {
            return 'approved_gateway';
        }

        public function verifyAndNormalize(string $rawBody, array $headers): \\App\\Data\\Billing\\VerifiedBillingWebhook
        {
            expect($rawBody)->toBe('signed-provider-body');
            expect($headers)->toHaveKey('x-provider-signature');

            return new \\App\\Data\\Billing\\VerifiedBillingWebhook(
                'acct_gateway_001',
                'evt_gateway_001',
                'invoice.payment_succeeded',
                ['provider_invoice_id' => $this->providerInvoiceId, 'payment_id' => 'pay_gateway_001', 'amount_minor' => 303, 'currency' => 'USD'],
                \\Carbon\\CarbonImmutable::now('UTC'),
                \\Carbon\\CarbonImmutable::now('UTC'),
            );
        }
    };

    $event = app(\\App\\Services\\Tenancy\\TenantBillingWebhookGateway::class)->ingest(
        'approved_gateway',
        $adapter,
        'signed-provider-body',
        ['x-provider-signature' => 'verified-by-adapter'],
    );

    expect($event->tenant_id)->toBe($tenant->id)
        ->and($event->provider_account_id)->toBe('acct_gateway_001')
        ->and($event->payload['invoice_id'])->toBe($invoice->id)
        ->and($event->provider_occurred_at)->not->toBeNull()
        ->and($event->received_at->diffInSeconds(now('UTC')))->toBeLessThan(5);
});

it('rejects provider adapter mismatch and stale signature timestamps before tenant lookup', function () {
    $adapter = new class implements \\App\\Contracts\\Billing\\BillingProviderAdapter {
        public function provider(): string
        {
            return 'different_gateway';
        }

        public function verifyAndNormalize(string $rawBody, array $headers): \\App\\Data\\Billing\\VerifiedBillingWebhook
        {
            throw new RuntimeException('must_not_be_called');
        }
    };

    expect(fn () => app(\\App\\Services\\Tenancy\\TenantBillingWebhookGateway::class)->ingest(
        'approved_gateway',
        $adapter,
        'body',
        [],
    ))->toThrow(InvalidArgumentException::class, 'billing_webhook_provider_adapter_mismatch');

    $staleAdapter = new class implements \\App\\Contracts\\Billing\\BillingProviderAdapter {
        public function provider(): string
        {
            return 'approved_gateway';
        }

        public function verifyAndNormalize(string $rawBody, array $headers): \\App\\Data\\Billing\\VerifiedBillingWebhook
        {
            return new \\App\\Data\\Billing\\VerifiedBillingWebhook(
                'unmapped_account',
                'evt_stale_001',
                'invoice.payment_succeeded',
                ['invoice_id' => 1, 'payment_id' => 'pay_stale_001', 'amount_minor' => 1, 'currency' => 'USD'],
                \\Carbon\\CarbonImmutable::now('UTC')->subMinutes(10),
                \\Carbon\\CarbonImmutable::now('UTC')->subMinutes(10),
            );
        }
    };

    expect(fn () => app(\\App\\Services\\Tenancy\\TenantBillingWebhookGateway::class)->ingest(
        'approved_gateway',
        $staleAdapter,
        'body',
        [],
    ))->toThrow(RuntimeException::class, 'billing_webhook_signature_timestamp_outside_window');
});

it('rejects inactive or unverified provider account mappings', function () {
    $tenant = createBillingOutboxTenant('billing-provider-unverified');
    DB::table('tenant_billing_provider_accounts')->insert([
        'tenant_id' => $tenant->id,
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_unverified_001',
        'status' => 'active',
        'verified_at' => null,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(fn () => app(\\App\\Services\\Tenancy\\TenantBillingProviderAccountResolver::class)->resolve(
        'approved_gateway',
        'acct_unverified_001',
    ))->toThrow(RuntimeException::class, 'billing_provider_account_unmapped');
});

it('keeps provider account tenant and external identity immutable', function () {
    $tenant = createBillingOutboxTenant('billing-provider-account-immutable');
    DB::table('tenant_billing_provider_accounts')->insert([
        'tenant_id' => $tenant->id,
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_immutable_mapping',
        'status' => 'inactive',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(fn () => DB::table('tenant_billing_provider_accounts')
        ->where('provider_account_id', 'acct_immutable_mapping')
        ->update(['provider_account_id' => 'acct_reassigned']))
        ->toThrow(\\Illuminate\\Database\\QueryException::class);

    expect(fn () => DB::table('tenant_billing_provider_accounts')
        ->where('provider_account_id', 'acct_immutable_mapping')
        ->update(['tenant_id' => createBillingOutboxTenant('billing-provider-account-other')->id]))
        ->toThrow(\\Illuminate\\Database\\QueryException::class);
});

it('marks an invoice and subscription past due after a verified payment failure', function () {
    $tenant = createBillingOutboxTenant('billing-payment-failure');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_payment_failure');
    $event = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_payment_failed_001',
        'invoice.payment_failed',
        ['invoice_id' => $invoice->id, 'failure_code' => 'card_declined'],
        now('UTC'),
        'acct_payment_failure',
    );

    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($invoice->fresh()->status)->toBe('past_due')
        ->and($invoice->subscription->fresh()->status)->toBe('past_due')
        ->and($invoice->subscription->fresh()->grace_ends_at->isFuture())->toBeTrue()
        ->and(TenantBillingOutboxMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'billing.payment.failed')
            ->exists())->toBeTrue();
});

it('renews a subscription only against a fully settled tenant invoice', function () {
    $tenant = createBillingOutboxTenant('billing-subscription-renewal');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_subscription_renewal');
    $subscription = $invoice->subscription;

    app(TenantBillingLedgerService::class)->recordConfirmedPayment($tenant->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'renewal-payment-ledger-1',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_subscription_renewal',
        'provider_payment_id' => 'pay_renewal_001',
        'received_at' => now('UTC'),
    ]);

    $periodStart = $subscription->current_period_ends_at->startOfSecond();
    $periodEnd = $periodStart->addMonth();
    $payload = [
        'subscription_id' => $subscription->id,
        'settled_invoice_id' => $invoice->id,
        'period_starts_at' => $periodStart->format('Y-m-d\\TH:i:s.uP'),
        'period_ends_at' => $periodEnd->format('Y-m-d\\TH:i:s.uP'),
    ];
    $event = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_subscription_renewed_001',
        'subscription.renewed',
        $payload,
        now('UTC'),
        'acct_subscription_renewal',
    );

    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    $subscription->refresh();
    expect($event->fresh()->status)->toBe('processed')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->current_period_starts_at->startOfSecond()->equalTo($periodStart))->toBeTrue()
        ->and($subscription->current_period_ends_at->startOfSecond()->equalTo($periodEnd->startOfSecond()))->toBeTrue()
        ->and(TenantBillingOutboxMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'billing.subscription.renewed')
            ->exists())->toBeTrue();

    $duplicate = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_subscription_renewed_002',
        'subscription.renewed',
        $payload,
        now('UTC'),
        'acct_subscription_renewal',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0)
        ->and($duplicate->fresh()->status)->toBe('processed')
        ->and(TenantBillingOutboxMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'billing.subscription.renewed')
            ->count())->toBe(1);
});

it('records refunds idempotently and reduces the invoice net paid balance', function () {
    $tenant = createBillingOutboxTenant('billing-refund-ledger');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_refund_001');
    app(TenantBillingLedgerService::class)->recordConfirmedPayment($tenant->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'refund-test-payment-1',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_refund_001',
        'provider_payment_id' => 'pay_refund_001',
        'received_at' => now('UTC'),
    ]);

    $payload = [
        'payment_id' => 'pay_refund_001',
        'adjustment_id' => 'refund_001',
        'amount_minor' => 1200,
        'currency' => 'USD',
    ];
    $first = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id, 'approved_gateway', 'evt_refund_001', 'payment.refunded', $payload, now('UTC'), 'acct_refund_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    $duplicate = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id, 'approved_gateway', 'evt_refund_002', 'payment.refunded', $payload, now('UTC'), 'acct_refund_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    expect($first->fresh()->status)->toBe('processed')
        ->and($duplicate->fresh()->status)->toBe('processed')
        ->and($invoice->fresh()->amount_paid_minor)->toBe(3700)
        ->and($invoice->fresh()->status)->toBe('partially_paid')
        ->and(\\App\\Models\\TenantBillingAdjustment::query()->where('tenant_id', $tenant->id)->where('kind', 'refund')->count())->toBe(1);
});

it('records dispute opening and applies a lost dispute once without changing a won dispute balance', function () {
    $tenant = createBillingOutboxTenant('billing-dispute-ledger');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_dispute_001');
    app(TenantBillingLedgerService::class)->recordConfirmedPayment($tenant->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'dispute-test-payment-1',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_dispute_001',
        'provider_payment_id' => 'pay_dispute_001',
        'received_at' => now('UTC'),
    ]);

    $base = ['payment_id' => 'pay_dispute_001', 'amount_minor' => 1000, 'currency' => 'USD'];
    $opened = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id, 'approved_gateway', 'evt_dispute_open_1', 'payment.dispute_opened',
        $base + ['adjustment_id' => 'dispute_won_001'], now('UTC'), 'acct_dispute_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);
    expect($invoice->fresh()->amount_paid_minor)->toBe(4900);

    $won = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id, 'approved_gateway', 'evt_dispute_won_1', 'payment.dispute_won',
        $base + ['adjustment_id' => 'dispute_won_001'], now('UTC'), 'acct_dispute_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);
    expect($invoice->fresh()->amount_paid_minor)->toBe(4900);

    $lostBase = ['payment_id' => 'pay_dispute_001', 'amount_minor' => 1500, 'currency' => 'USD', 'adjustment_id' => 'dispute_lost_001'];
    $lostOpen = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id, 'approved_gateway', 'evt_dispute_open_2', 'payment.dispute_opened',
        $lostBase, now('UTC'), 'acct_dispute_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    $lost = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id, 'approved_gateway', 'evt_dispute_lost_1', 'payment.dispute_lost',
        $lostBase, now('UTC'), 'acct_dispute_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    expect($opened->fresh()->status)->toBe('processed')
        ->and($won->fresh()->status)->toBe('processed')
        ->and($lostOpen->fresh()->status)->toBe('processed')
        ->and($lost->fresh()->status)->toBe('processed')
        ->and($invoice->fresh()->amount_paid_minor)->toBe(3400)
        ->and(\\App\\Models\\TenantBillingAdjustment::query()->where('tenant_id', $tenant->id)->where('kind', 'dispute_lost')->count())->toBe(1);
});

it('keeps billing adjustments append-only after insertion', function () {
    $tenant = createBillingOutboxTenant('billing-adjustment-immutable');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_adjustment_immutable');
    $payment = app(TenantBillingLedgerService::class)->recordConfirmedPayment($tenant->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'immutable-adjustment-payment',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_adjustment_immutable',
        'provider_payment_id' => 'pay_immutable_adjustment',
        'received_at' => now('UTC'),
    ]);
    $adjustment = app(\\App\\Services\\Tenancy\\TenantBillingAdjustmentService::class)->recordProviderAdjustment(
        $tenant->id,
        'approved_gateway',
        'acct_adjustment_immutable',
        'evt_adjustment_immutable',
        'pay_immutable_adjustment',
        'refund_immutable',
        'refund',
        100,
        'USD',
        now('UTC'),
    );

    expect($adjustment->tenant_billing_payment_id)->toBe($payment->id)
        ->and(fn () => DB::table('tenant_billing_payments')->where('id', $payment->id)->update(['amount_minor' => 200]))
        ->toThrow(\\Illuminate\\Database\\QueryException::class)
        ->and(fn () => DB::table('tenant_billing_payments')->where('id', $payment->id)->delete())
        ->toThrow(\\Illuminate\\Database\\QueryException::class)
        ->and(fn () => DB::table('tenant_subscriptions')->where('id', $invoice->tenant_subscription_id)->update(['provider_account_id' => 'acct_rebound']))
        ->toThrow(\\Illuminate\\Database\\QueryException::class)
        ->and(fn () => DB::table('tenant_billing_adjustments')->where('id', $adjustment->id)->update(['amount_minor' => 200]))
        ->toThrow(\\Illuminate\\Database\\QueryException::class)
        ->and(fn () => DB::table('tenant_billing_adjustments')->where('id', $adjustment->id)->delete())
        ->toThrow(\\Illuminate\\Database\\QueryException::class);
});

it('keeps provider adapters disabled until a provider is explicitly configured', function () {
    expect(fn () => app(\\App\\Services\\Tenancy\\BillingProviderAdapterRegistry::class)->resolve('approved_gateway'))
        ->toThrow(RuntimeException::class, 'billing_provider_adapter_not_configured');
});

it('rejects payment idempotency-key reuse with a different provider account', function () {
    $tenant = createBillingOutboxTenant('billing-payment-account-idempotency');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_original_001');
    $service = app(TenantBillingLedgerService::class);
    $payment = [
        'collection_mode' => 'automated',
        'idempotency_key' => 'payment-account-bound-key',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_original_001',
        'provider_payment_id' => 'pay_account_bound_001',
        'received_at' => now('UTC'),
    ];
    $service->recordConfirmedPayment($tenant->id, $invoice->id, $payment);

    expect(fn () => $service->recordConfirmedPayment($tenant->id, $invoice->id, [
        ...$payment,
        'provider_account_id' => 'acct_attacker_002',
    ]))->toThrow(RuntimeException::class, 'billing_payment_idempotency_conflict');
});

it('does not allow a refund from another provider account to mutate a payment', function () {
    $tenant = createBillingOutboxTenant('billing-refund-account-binding');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_payment_owner_001');
    app(TenantBillingLedgerService::class)->recordConfirmedPayment($tenant->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'refund-account-bound-payment',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_payment_owner_001',
        'provider_payment_id' => 'pay_account_owner_001',
        'received_at' => now('UTC'),
    ]);

    expect(fn () => app(\\App\\Services\\Tenancy\\TenantBillingAdjustmentService::class)->recordProviderAdjustment(
        $tenant->id,
        'approved_gateway',
        'acct_other_tenant_account_002',
        'evt_cross_account_refund',
        'pay_account_owner_001',
        'refund_cross_account_001',
        'refund',
        100,
        'USD',
        now('UTC'),
    ))->toThrow(\\Illuminate\\Database\\Eloquent\\ModelNotFoundException::class);

    expect($invoice->fresh()->amount_paid_minor)->toBe(4900)
        ->and(\\App\\Models\\TenantBillingAdjustment::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('enforces tenant ownership on billing adjustment payment foreign keys', function () {
    $tenantA = createBillingOutboxTenant('billing-adjustment-fk-a');
    $tenantB = createBillingOutboxTenant('billing-adjustment-fk-b');
    $invoice = createBillingOutboxInvoice($tenantA, 4900, 'acct_adjustment_fk');
    $payment = app(TenantBillingLedgerService::class)->recordConfirmedPayment($tenantA->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'adjustment-fk-payment',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_adjustment_fk',
        'provider_payment_id' => 'pay_adjustment_fk',
        'received_at' => now('UTC'),
    ]);

    expect(fn () => DB::table('tenant_billing_adjustments')->insert([
        'tenant_id' => $tenantB->id,
        'tenant_billing_payment_id' => $payment->id,
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_adjustment_fk',
        'provider_adjustment_id' => 'refund_cross_tenant_fk',
        'kind' => 'refund',
        'idempotency_key' => 'billing.adjustment:cross-tenant-fk',
        'amount_minor' => 100,
        'currency' => 'USD',
        'occurred_at' => now('UTC'),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]))->toThrow(\\Illuminate\\Database\\QueryException::class);
});

it('resolves a provider adapter only through the explicit billing provider registry', function () {
    $adapter = new class implements \\App\\Contracts\\Billing\\BillingProviderAdapter {
        public function provider(): string
        {
            return 'configured_gateway';
        }

        public function verifyAndNormalize(string $rawBody, array $headers): \\App\\Data\\Billing\\VerifiedBillingWebhook
        {
            throw new RuntimeException('not_called_in_registry_test');
        }
    };
    app()->instance($adapter::class, $adapter);
    config()->set('billing.providers.configured_gateway', $adapter::class);

    expect(app(\\App\\Services\\Tenancy\\BillingProviderAdapterRegistry::class)->resolve('configured_gateway'))
        ->toBe($adapter);
});

it('rejects a renewal notification when its provider account did not settle the invoice', function () {
    $tenant = createBillingOutboxTenant('billing-renewal-account-mismatch');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_real_settlement');
    $subscription = $invoice->subscription;

    app(TenantBillingLedgerService::class)->recordConfirmedPayment($tenant->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'renewal-account-mismatch-payment',
        'amount_minor' => 4900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_account_id' => 'acct_real_settlement',
        'provider_payment_id' => 'pay_renewal_mismatch',
        'received_at' => now('UTC'),
    ]);

    $periodStart = $subscription->current_period_ends_at->startOfSecond();
    $periodEnd = $periodStart->addMonth();
    $event = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_renewal_wrong_account',
        'subscription.renewed',
        [
            'subscription_id' => $subscription->id,
            'settled_invoice_id' => $invoice->id,
            'period_starts_at' => $periodStart->format('Y-m-d\\TH:i:s.uP'),
            'period_ends_at' => $periodEnd->format('Y-m-d\\TH:i:s.uP'),
        ],
        now('UTC'),
        'acct_claimed_but_not_paid',
    );

    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0)
        ->and($event->fresh()->status)->toBe('dead_lettered')
        ->and($subscription->fresh()->status)->toBe('active')
        ->and($subscription->fresh()->current_period_ends_at->startOfSecond()->equalTo($periodStart))->toBeTrue();
});

it('does not extend the platform grace deadline on repeated provider past-due events', function () {
    $tenant = createBillingOutboxTenant('billing-past-due-idempotency');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_past_due_001');
    $subscription = $invoice->subscription;

    $first = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_past_due_001',
        'subscription.past_due',
        ['subscription_id' => $subscription->id],
        now('UTC'),
        'acct_past_due_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);
    $graceDeadline = $subscription->fresh()->grace_ends_at->toIso8601String();

    $second = app(TenantBillingWebhookInbox::class)->acceptVerifiedEvent(
        $tenant->id,
        'approved_gateway',
        'evt_past_due_002',
        'subscription.past_due',
        ['subscription_id' => $subscription->id],
        now('UTC'),
        'acct_past_due_001',
    );
    expect(Artisan::call('billing:webhooks:reconcile', ['--limit' => 10]))->toBe(0);

    expect($first->fresh()->status)->toBe('processed')
        ->and($second->fresh()->status)->toBe('processed')
        ->and($subscription->fresh()->grace_ends_at->toIso8601String())->toBe($graceDeadline);
});

it('resolves provider invoice and subscription references only within the verified account', function () {
    $tenant = createBillingOutboxTenant('billing-provider-reference-scope');
    $invoice = createBillingOutboxInvoice($tenant, 4900, 'acct_reference_scope');

    $mapped = app(\\App\\Services\\Tenancy\\TenantBillingWebhookReferenceResolver::class)->resolve(
        $tenant->id,
        'approved_gateway',
        'acct_reference_scope',
        'invoice.payment_failed',
        ['provider_invoice_id' => $invoice->provider_invoice_id, 'failure_code' => 'card_declined'],
    );
    expect($mapped['invoice_id'])->toBe($invoice->id)
        ->and($mapped)->not->toHaveKey('provider_invoice_id');

    expect(fn () => app(\\App\\Services\\Tenancy\\TenantBillingWebhookReferenceResolver::class)->resolve(
        $tenant->id,
        'approved_gateway',
        'acct_other_reference_scope',
        'invoice.payment_failed',
        ['provider_invoice_id' => $invoice->provider_invoice_id, 'failure_code' => 'card_declined'],
    ))->toThrow(InvalidArgumentException::class, 'billing_provider_invoice_unmapped');

    $subscription = $invoice->subscription;
    $mappedSubscription = app(\\App\\Services\\Tenancy\\TenantBillingWebhookReferenceResolver::class)->resolve(
        $tenant->id,
        'approved_gateway',
        'acct_reference_scope',
        'subscription.past_due',
        ['provider_subscription_id' => $subscription->provider_subscription_id],
    );
    expect($mappedSubscription['subscription_id'])->toBe($subscription->id)
        ->and($mappedSubscription)->not->toHaveKey('provider_subscription_id');
});

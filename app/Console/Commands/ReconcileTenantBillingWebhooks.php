<?php

namespace App\Console\Commands;

use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingWebhookEvent;
use App\Services\Tenancy\TenantBillingAdjustmentService;
use App\Services\Tenancy\TenantBillingFailureReconciler;
use App\Services\Tenancy\TenantBillingLedgerService;
use App\Services\Tenancy\TenantBillingOperationsConnection;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Tenancy\TenantSubscriptionLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ReconcileTenantBillingWebhooks extends Command
{
    protected $signature = 'billing:webhooks:reconcile {--limit=100}';

    protected $description = 'Reconcile verified tenant billing webhooks with bounded retries and dead-lettering';

    private const MAX_ATTEMPTS = 12;

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The limit must be between 1 and 500.');

            return self::FAILURE;
        }

        try {
            return app(TenantBillingOperationsConnection::class)->run(
                fn (): int => $this->reconcile((int) $limit),
            );
        } catch (Throwable $exception) {
            Log::error('Billing webhook reconciliation could not start.', [
                'reason_code' => 'billing_operations_connection_unavailable',
                'exception_class' => $exception::class,
            ]);
            $this->error('Billing reconciliation requires a valid, separately provisioned PostgreSQL operations connection to the same database.');

            return self::FAILURE;
        }
    }

    private function reconcile(int $limit): int
    {
        $now = CarbonImmutable::now('UTC');
        $ids = TenantBillingWebhookEvent::query()
            ->where(function ($query) use ($now): void {
                $query->where(function ($pending) use ($now): void {
                    $pending->where('status', 'pending')->where('available_at', '<=', $now);
                })->orWhere(function ($stale) use ($now): void {
                    $stale->where('status', 'processing')->where('locked_at', '<=', $now->subMinutes(5));
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $processed = 0;
        foreach ($ids as $id) {
            if ($this->process((int) $id)) {
                $processed++;
            }
        }

        $this->info("Reconciled {$processed} billing webhook event(s).");

        return self::SUCCESS;
    }

    private function process(int $eventId): bool
    {
        $claimed = DB::transaction(function () use ($eventId): ?TenantBillingWebhookEvent {
            $event = TenantBillingWebhookEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if ($event === null
                || ($event->status !== 'pending' && ! ($event->status === 'processing' && $event->locked_at?->lessThan(now('UTC')->subMinutes(5))))
                || $event->available_at->isFuture()) {
                return null;
            }

            $event->forceFill([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
                'locked_at' => now('UTC'),
                'last_error_code' => null,
            ])->save();

            return $event->refresh();
        }, 3);

        if ($claimed === null) {
            return false;
        }

        try {
            app(TenantDatabaseContext::class)->run(
                $claimed->tenant_id,
                null,
                fn () => DB::transaction(function () use ($claimed): void {
                    $event = TenantBillingWebhookEvent::query()
                        ->whereKey($claimed->id)
                        ->where('tenant_id', $claimed->tenant_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($event->status !== 'processing') {
                        return;
                    }

                    $this->applyVerifiedEvent($event);

                    $event->forceFill([
                        'status' => 'processed',
                        'processed_at' => now('UTC'),
                        'locked_at' => null,
                        'last_error_code' => null,
                    ])->save();
                }, 3),
            );

            return true;
        } catch (Throwable $exception) {
            $permanent = $exception instanceof InvalidArgumentException
                || $exception instanceof ModelNotFoundException;

            DB::transaction(function () use ($claimed, $permanent): void {
                $event = TenantBillingWebhookEvent::query()
                    ->whereKey($claimed->id)
                    ->where('tenant_id', $claimed->tenant_id)
                    ->lockForUpdate()
                    ->first();

                if ($event === null || $event->status !== 'processing') {
                    return;
                }

                $dead = $permanent || $event->attempts >= self::MAX_ATTEMPTS;
                $delaySeconds = min(21600, 30 * (2 ** min(10, max(0, $event->attempts - 1))));

                $event->forceFill([
                    'status' => $dead ? 'dead_lettered' : 'pending',
                    'available_at' => $dead ? $event->available_at : now('UTC')->addSeconds($delaySeconds),
                    'locked_at' => null,
                    'dead_lettered_at' => $dead ? now('UTC') : null,
                    'last_error_code' => $permanent ? 'billing_webhook_payload_rejected' : 'billing_webhook_processing_failed',
                ])->save();
            }, 3);

            Log::warning('Tenant billing webhook reconciliation failed.', [
                'webhook_event_id' => $claimed->id,
                'tenant_id' => $claimed->tenant_id,
                'reason_code' => $permanent ? 'billing_webhook_payload_rejected' : 'billing_webhook_processing_failed',
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function applyVerifiedEvent(TenantBillingWebhookEvent $event): void
    {
        $payload = $event->payload ?? [];

        if (in_array($event->event_type, ['payment.refunded', 'payment.dispute_opened', 'payment.dispute_won', 'payment.dispute_lost'], true)) {
            $amount = filter_var($payload['amount_minor'] ?? null, FILTER_VALIDATE_INT);
            $paymentId = $payload['payment_id'] ?? null;
            $adjustmentId = $payload['adjustment_id'] ?? null;
            $currency = $payload['currency'] ?? null;
            if ($amount === false || $amount < 1 || ! is_string($paymentId) || ! is_string($adjustmentId)
                || ! is_string($currency)) {
                throw new InvalidArgumentException('billing_adjustment_payload_invalid');
            }

            $kind = match ($event->event_type) {
                'payment.refunded' => 'refund',
                'payment.dispute_opened' => 'dispute_opened',
                'payment.dispute_won' => 'dispute_won',
                'payment.dispute_lost' => 'dispute_lost',
            };

            app(TenantBillingAdjustmentService::class)->recordProviderAdjustment(
                $event->tenant_id,
                $event->provider,
                $event->provider_account_id,
                $event->provider_event_id,
                $paymentId,
                $adjustmentId,
                $kind,
                $amount,
                $currency,
                $event->provider_occurred_at ?? $event->received_at,
            );

            return;
        }

        if ($event->event_type === 'invoice.payment_failed') {
            $invoiceId = filter_var($payload['invoice_id'] ?? null, FILTER_VALIDATE_INT);
            $failureCode = $payload['failure_code'] ?? null;
            if ($invoiceId === false || $invoiceId < 1 || ! is_string($failureCode)) {
                throw new InvalidArgumentException('billing_payment_failure_payload_invalid');
            }

            app(TenantBillingFailureReconciler::class)->recordInvoicePaymentFailure(
                $event->tenant_id,
                $invoiceId,
                $event->provider,
                $event->provider_account_id,
                $event->provider_event_id,
                $failureCode,
            );

            return;
        }

        if ($event->event_type === 'invoice.payment_succeeded') {
            $invoiceId = filter_var($payload['invoice_id'] ?? null, FILTER_VALIDATE_INT);
            $amount = filter_var($payload['amount_minor'] ?? null, FILTER_VALIDATE_INT);
            $paymentId = $payload['payment_id'] ?? null;
            $currency = $payload['currency'] ?? null;
            if ($invoiceId === false || $invoiceId < 1 || $amount === false || $amount < 1
                || ! is_string($paymentId) || trim($paymentId) === '' || strlen($paymentId) > 160
                || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new InvalidArgumentException('billing_webhook_payment_payload_invalid');
            }

            app(TenantBillingLedgerService::class)->recordConfirmedPayment($event->tenant_id, $invoiceId, [
                'collection_mode' => 'automated',
                'idempotency_key' => 'webhook:'.hash('sha256', $event->provider.':'.$event->provider_account_id.':'.$event->provider_event_id),
                'amount_minor' => $amount,
                'currency' => $currency,
                'provider' => $event->provider,
                'provider_account_id' => $event->provider_account_id,
                'provider_payment_id' => $paymentId,
                'received_at' => $event->received_at,
            ]);

            // Checkout path: provisional trialing subscriptions become active only after
            // the same webhook ledger settlement that marks the invoice paid.
            $invoice = TenantBillingInvoice::query()
                ->where('tenant_id', $event->tenant_id)
                ->whereKey($invoiceId)
                ->first();

            if ($invoice !== null
                && $invoice->status === 'paid'
                && $invoice->tenant_subscription_id !== null) {
                $subscription = $invoice->subscription()
                    ->where('tenant_id', $event->tenant_id)
                    ->first();

                if ($subscription !== null
                    && $subscription->status === 'trialing'
                    && $subscription->billing_provider === $event->provider
                    && $subscription->provider_account_id === $event->provider_account_id) {
                    app(TenantSubscriptionLifecycle::class)->transition(
                        $event->tenant_id,
                        (int) $subscription->id,
                        'active',
                        null,
                        null,
                        null,
                        $event->provider,
                        $event->provider_account_id,
                    );
                }
            }

            return;
        }

        $subscriptionId = filter_var($payload['subscription_id'] ?? null, FILTER_VALIDATE_INT);
        if ($subscriptionId === false || $subscriptionId < 1) {
            throw new InvalidArgumentException('billing_webhook_subscription_payload_invalid');
        }

        $lifecycle = app(TenantSubscriptionLifecycle::class);
        if ($event->event_type === 'subscription.renewed') {
            $invoiceId = filter_var($payload['settled_invoice_id'] ?? null, FILTER_VALIDATE_INT);
            $periodStart = $payload['period_starts_at'] ?? null;
            $periodEnd = $payload['period_ends_at'] ?? null;
            if ($invoiceId === false || $invoiceId < 1 || ! is_string($periodStart) || ! is_string($periodEnd)) {
                throw new InvalidArgumentException('tenant_subscription_renewal_payload_invalid');
            }

            $lifecycle->renewFromSettledInvoice(
                $event->tenant_id,
                $subscriptionId,
                $invoiceId,
                CarbonImmutable::parse($periodStart),
                CarbonImmutable::parse($periodEnd),
                $event->provider,
                $event->provider_account_id,
            );
        } elseif ($event->event_type === 'subscription.past_due') {
            $graceDays = max(1, min(30, (int) config('billing.payment_failure_grace_days', 7)));
            $lifecycle->transition(
                $event->tenant_id,
                $subscriptionId,
                'past_due',
                CarbonImmutable::now('UTC')->addDays($graceDays),
                null,
                null,
                $event->provider,
                $event->provider_account_id,
            );
        } elseif ($event->event_type === 'subscription.canceled') {
            $lifecycle->transition($event->tenant_id, $subscriptionId, 'canceled', null, null, null, $event->provider, $event->provider_account_id);
        } elseif ($event->event_type === 'subscription.expired') {
            $lifecycle->transition($event->tenant_id, $subscriptionId, 'expired', null, null, null, $event->provider, $event->provider_account_id);
        } elseif ($event->event_type === 'subscription.reactivated') {
            $invoiceId = filter_var($payload['settled_invoice_id'] ?? null, FILTER_VALIDATE_INT);
            if ($invoiceId === false || $invoiceId < 1) {
                throw new InvalidArgumentException('billing_webhook_settled_invoice_required');
            }
            $lifecycle->transition($event->tenant_id, $subscriptionId, 'active', null, null, $invoiceId, $event->provider, $event->provider_account_id);
        } else {
            throw new InvalidArgumentException('billing_webhook_event_type_unsupported');
        }
    }
}

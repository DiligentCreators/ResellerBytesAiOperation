<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantBillingAdjustment;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Services\Audit\AuditEventWriter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingAdjustmentService
{
    public function recordProviderAdjustment(
        string $tenantId,
        string $provider,
        string $providerAccountId,
        string $providerEventId,
        string $providerPaymentId,
        string $providerAdjustmentId,
        string $kind,
        int $amountMinor,
        string $currency,
        DateTimeInterface $occurredAt,
    ): TenantBillingAdjustment {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)
            || ! in_array($kind, ['refund', 'dispute_opened', 'dispute_won', 'dispute_lost'], true)
            || trim($providerAccountId) === '' || strlen($providerAccountId) > 160
            || trim($providerEventId) === '' || strlen($providerEventId) > 180
            || trim($providerPaymentId) === '' || strlen($providerPaymentId) > 160
            || trim($providerAdjustmentId) === '' || strlen($providerAdjustmentId) > 180
            || $amountMinor < 1 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('billing_adjustment_input_invalid');
        }

        $occurredAt = CarbonImmutable::instance($occurredAt)->utc();
        $idempotencyKey = 'billing.adjustment:'.hash('sha256', $provider.':'.$providerAccountId.':'.$kind.':'.$providerAdjustmentId);

        return app(TenantDatabaseContext::class)->run($tenantId, null, fn (): TenantBillingAdjustment => DB::transaction(function () use ($tenantId, $provider, $providerAccountId, $providerEventId, $providerPaymentId, $providerAdjustmentId, $kind, $amountMinor, $currency, $occurredAt, $idempotencyKey): TenantBillingAdjustment {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            $payment = TenantBillingPayment::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', $provider)
                ->where('provider_account_id', $providerAccountId)
                ->where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = TenantBillingAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->tenant_billing_payment_id !== $payment->id
                    || $existing->amount_minor !== $amountMinor
                    || $existing->currency !== $currency) {
                    throw new RuntimeException('billing_adjustment_idempotency_conflict');
                }

                return $existing;
            }

            if ($payment->currency !== $currency || $amountMinor > $payment->amount_minor) {
                throw new InvalidArgumentException('billing_adjustment_payment_mismatch');
            }

            $invoice = TenantBillingInvoice::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($payment->tenant_billing_invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->provider !== $provider || $invoice->provider_account_id !== $providerAccountId) {
                throw new InvalidArgumentException('billing_invoice_provider_account_mismatch');
            }

            if ($kind === 'dispute_won' || $kind === 'dispute_lost') {
                $opened = TenantBillingAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $provider)
                    ->where('provider_account_id', $providerAccountId)
                    ->where('provider_adjustment_id', $providerAdjustmentId)
                    ->where('kind', 'dispute_opened')
                    ->where('tenant_billing_payment_id', $payment->id)
                    ->first();

                if ($opened === null || $opened->amount_minor !== $amountMinor || $opened->currency !== $currency) {
                    throw new InvalidArgumentException('billing_dispute_opening_record_required');
                }

                $resolved = TenantBillingAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $provider)
                    ->where('provider_account_id', $providerAccountId)
                    ->where('provider_adjustment_id', $providerAdjustmentId)
                    ->whereIn('kind', ['dispute_won', 'dispute_lost'])
                    ->exists();

                if ($resolved) {
                    throw new RuntimeException('billing_dispute_already_resolved');
                }
            }

            if ($kind === 'dispute_opened') {
                $opened = TenantBillingAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $provider)
                    ->where('provider_account_id', $providerAccountId)
                    ->where('provider_adjustment_id', $providerAdjustmentId)
                    ->whereIn('kind', ['dispute_opened', 'dispute_won', 'dispute_lost'])
                    ->exists();

                if ($opened) {
                    throw new RuntimeException('billing_dispute_already_recorded');
                }

                $alreadyAdjusted = (int) TenantBillingAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('tenant_billing_payment_id', $payment->id)
                    ->whereIn('kind', ['refund', 'dispute_lost'])
                    ->sum('amount_minor');
                if ($alreadyAdjusted + $amountMinor > $payment->amount_minor) {
                    throw new InvalidArgumentException('billing_dispute_amount_exceeds_paid_balance');
                }
            }

            if ($kind === 'refund' || $kind === 'dispute_lost') {
                $alreadyAdjusted = (int) TenantBillingAdjustment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('tenant_billing_payment_id', $payment->id)
                    ->whereIn('kind', ['refund', 'dispute_lost'])
                    ->sum('amount_minor');

                if ($alreadyAdjusted + $amountMinor > $payment->amount_minor
                    || $invoice->amount_paid_minor < $amountMinor) {
                    throw new InvalidArgumentException('billing_adjustment_amount_exceeds_paid_balance');
                }
            }

            try {
                $adjustment = TenantBillingAdjustment::query()->create([
                    'tenant_id' => $tenantId,
                    'tenant_billing_payment_id' => $payment->id,
                    'provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_adjustment_id' => $providerAdjustmentId,
                    'kind' => $kind,
                    'idempotency_key' => $idempotencyKey,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'occurred_at' => $occurredAt,
                ]);
            } catch (QueryException $exception) {
                throw new RuntimeException('billing_adjustment_idempotency_conflict', previous: $exception);
            }

            if ($kind === 'refund' || $kind === 'dispute_lost') {
                $paid = $invoice->amount_paid_minor - $amountMinor;
                $invoice->forceFill([
                    'amount_paid_minor' => $paid,
                    'status' => $invoice->status === 'void' ? 'void' : ($paid === 0 ? 'open' : 'partially_paid'),
                    'paid_at' => null,
                ])->save();

                if ($invoice->status !== 'void' && $invoice->tenant_subscription_id !== null) {
                    $subscription = $invoice->subscription()
                        ->where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (in_array($subscription->status, ['active', 'trialing'], true)) {
                        $graceDays = max(1, min(30, (int) config('billing.payment_failure_grace_days', 7)));
                        app(TenantSubscriptionLifecycle::class)->transition(
                            $tenantId,
                            (int) $subscription->id,
                            'past_due',
                            CarbonImmutable::now('UTC')->addDays($graceDays),
                            null,
                            null,
                            $provider,
                            $providerAccountId,
                        );
                    }
                }
            }

            app(AuditEventWriter::class)->append([
                'actor_type' => 'system',
                'actor_id' => null,
                'tenant_id' => $tenantId,
                'event_type' => 'tenant_billing.adjustment_recorded',
                'subject_type' => 'tenant_billing_adjustment',
                'subject_id' => (string) $adjustment->id,
                'metadata' => [
                    'provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_event_id' => $providerEventId,
                    'provider_payment_id' => $providerPaymentId,
                    'kind' => $kind,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'invoice_id' => $invoice->id,
                    'invoice_status' => $invoice->fresh()->status,
                ],
                'occurred_at' => $occurredAt,
            ]);

            app(TenantBillingOutbox::class)->enqueue(
                $tenantId,
                $idempotencyKey,
                'billing.payment.adjustment_recorded',
                'tenant_billing_payment',
                $payment->id,
                [
                    'adjustment_id' => $adjustment->id,
                    'kind' => $kind,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'invoice_id' => $invoice->id,
                    'invoice_status' => $invoice->fresh()->status,
                ],
            );

            return $adjustment;
        }, 3));
    }
}

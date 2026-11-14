<?php

namespace App\Services\Tenancy;

use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingLedgerService
{
    /**
     * Record a payment already confirmed by the collection channel. This method
     * never initiates a charge or trusts an unverified provider callback.
     *
     * @param array{collection_mode: string, idempotency_key: string, amount_minor: int, currency: string, provider?: ?string, provider_payment_id?: ?string, reference?: ?string, recorded_by_user_id?: ?int, received_at: mixed} $payment
     */
    public function recordConfirmedPayment(string $tenantId, int $invoiceId, array $payment): TenantBillingPayment
    {
        if (! in_array($payment['collection_mode'] ?? null, ['manual', 'automated'], true)
            || ! is_string($payment['idempotency_key'] ?? null)
            || trim($payment['idempotency_key']) === ''
            || ! is_int($payment['amount_minor'] ?? null)
            || $payment['amount_minor'] < 1
            || ! is_string($payment['currency'] ?? null)
            || ! preg_match('/^[A-Z]{3}$/', $payment['currency'])) {
            throw new InvalidArgumentException('billing_payment_input_invalid');
        }

        if ($payment['collection_mode'] === 'manual'
            && (! is_int($payment['recorded_by_user_id'] ?? null) || empty($payment['reference']))) {
            throw new InvalidArgumentException('billing_manual_payment_evidence_required');
        }
        if ($payment['collection_mode'] === 'automated'
            && (empty($payment['provider']) || empty($payment['provider_payment_id']))) {
            throw new InvalidArgumentException('billing_provider_payment_reference_required');
        }

        return DB::transaction(function () use ($tenantId, $invoiceId, $payment): TenantBillingPayment {
            $invoice = TenantBillingInvoice::query()
                ->whereKey($invoiceId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = TenantBillingPayment::query()
                ->where('idempotency_key', $payment['idempotency_key'])
                ->first();
            if ($existing !== null) {
                if ($existing->tenant_id !== $tenantId
                    || (int) $existing->tenant_billing_invoice_id !== $invoiceId
                    || $existing->amount_minor !== $payment['amount_minor']
                    || $existing->currency !== $payment['currency']) {
                    throw new RuntimeException('billing_payment_idempotency_conflict');
                }
                return $existing;
            }

            if ($invoice->currency !== $payment['currency']
                || $invoice->status === 'void'
                || $invoice->amount_paid_minor + $payment['amount_minor'] > $invoice->amount_due_minor) {
                throw new RuntimeException('billing_invoice_payment_invariant_failed');
            }

            $record = TenantBillingPayment::create([
                'tenant_id' => $tenantId,
                'tenant_billing_invoice_id' => $invoice->id,
                'collection_mode' => $payment['collection_mode'],
                'idempotency_key' => $payment['idempotency_key'],
                'provider' => $payment['provider'] ?? null,
                'provider_payment_id' => $payment['provider_payment_id'] ?? null,
                'status' => 'succeeded',
                'currency' => $payment['currency'],
                'amount_minor' => $payment['amount_minor'],
                'reference' => $payment['reference'] ?? null,
                'recorded_by_user_id' => $payment['recorded_by_user_id'] ?? null,
                'received_at' => $payment['received_at'],
            ]);

            $paid = $invoice->amount_paid_minor + $record->amount_minor;
            $invoice->forceFill([
                'amount_paid_minor' => $paid,
                'status' => $paid === $invoice->amount_due_minor ? 'paid' : 'partially_paid',
                'paid_at' => $paid === $invoice->amount_due_minor ? $record->received_at : null,
            ])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => isset($payment['recorded_by_user_id']) ? 'user' : 'system',
                'actor_id' => isset($payment['recorded_by_user_id']) ? (string) $payment['recorded_by_user_id'] : null,
                'tenant_id' => $tenantId,
                'event_type' => 'tenant_billing.payment_recorded',
                'subject_type' => 'tenant_billing_payment',
                'subject_id' => (string) $record->id,
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'collection_mode' => $record->collection_mode,
                    'amount_minor' => $record->amount_minor,
                    'currency' => $record->currency,
                    'invoice_status' => $invoice->status,
                ],
                'occurred_at' => $record->received_at,
            ]);

            return $record;
        }, 3);
    }
}

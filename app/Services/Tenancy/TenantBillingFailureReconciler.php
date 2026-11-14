<?php

namespace App\\Services\\Tenancy;

use App\\Models\\Tenant;
use App\\Models\\TenantBillingInvoice;
use App\\Services\\Audit\\AuditEventWriter;
use Carbon\\CarbonImmutable;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;

class TenantBillingFailureReconciler
{
    public function recordInvoicePaymentFailure(string $tenantId, int $invoiceId, string $provider, string $providerAccountId, string $providerEventId, string $failureCode): void
    {
        if (! preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $failureCode)
            || ! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)
            || trim($providerAccountId) === '' || strlen($providerAccountId) > 160
            || trim($providerEventId) === '' || strlen($providerEventId) > 180) {
            throw new InvalidArgumentException('billing_payment_failure_input_invalid');
        }

        app(TenantDatabaseContext::class)->run($tenantId, null, function () use ($tenantId, $invoiceId, $provider, $providerAccountId, $providerEventId, $failureCode): void {
            DB::transaction(function () use ($tenantId, $invoiceId, $provider, $providerEventId, $failureCode): void {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

                $invoice = TenantBillingInvoice::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($invoice->status === 'paid' || $invoice->status === 'void') {
                    return;
                }

                $previousStatus = $invoice->status;
                if ($invoice->status !== 'past_due') {
                    $invoice->forceFill(['status' => 'past_due'])->save();
                }

                if ($invoice->tenant_subscription_id !== null) {
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
                        );
                    }
                }

                app(AuditEventWriter::class)->append([
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'tenant_id' => $tenantId,
                    'event_type' => 'tenant_billing.payment_failed',
                    'subject_type' => 'tenant_billing_invoice',
                    'subject_id' => (string) $invoice->id,
                    'metadata' => [
                        'provider_account_id' => $providerAccountId,
                    'provider_event_id' => $providerEventId,
                        'failure_code' => $failureCode,
                        'previous_invoice_status' => $previousStatus,
                        'new_invoice_status' => $invoice->fresh()->status,
                    ],
                    'occurred_at' => now('UTC'),
                ]);

                app(TenantBillingOutbox::class)->enqueue(
                    $tenantId,
                    'billing.payment.failed:'.hash('sha256', $provider.':'.$providerAccountId.':'.$providerEventId),
                    'billing.payment.failed',
                    'tenant_billing_invoice',
                    $invoice->id,
                    [
                        'invoice_id' => $invoice->id,
                        'failure_code' => $failureCode,
                        'invoice_status' => $invoice->fresh()->status,
                    ],
                );
            }, 3);
        });
    }
}

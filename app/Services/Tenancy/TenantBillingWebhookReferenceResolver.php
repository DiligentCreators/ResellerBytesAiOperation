<?php

namespace App\\Services\\Tenancy;

use App\\Models\\TenantBillingInvoice;
use App\\Models\\TenantSubscription;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingWebhookReferenceResolver
{
    /**
     * Translate verified provider references into local IDs only after the
     * signed provider account has been mapped to this tenant.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resolve(string $tenantId, string $provider, string $providerAccountId, string $eventType, array $payload): array
    {
        return app(TenantDatabaseContext::class)->run($tenantId, null, function () use ($tenantId, $provider, $providerAccountId, $eventType, $payload): array {
            if (in_array($eventType, ['invoice.payment_succeeded', 'invoice.payment_failed'], true)) {
                $providerInvoiceId = $payload['provider_invoice_id'] ?? null;
                if (! is_string($providerInvoiceId) || trim($providerInvoiceId) === '' || strlen($providerInvoiceId) > 180) {
                    throw new InvalidArgumentException('billing_provider_invoice_reference_invalid');
                }

                $invoice = TenantBillingInvoice::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $provider)
                    ->where('provider_account_id', $providerAccountId)
                    ->where('provider_invoice_id', $providerInvoiceId)
                    ->first();

                if ($invoice === null) {
                    throw new RuntimeException('billing_provider_invoice_unmapped');
                }

                unset($payload['provider_invoice_id']);
                $payload['invoice_id'] = $invoice->id;
            }

            if (str_starts_with($eventType, 'subscription.')) {
                $providerSubscriptionId = $payload['provider_subscription_id'] ?? null;
                if (! is_string($providerSubscriptionId) || trim($providerSubscriptionId) === '' || strlen($providerSubscriptionId) > 160) {
                    throw new InvalidArgumentException('billing_provider_subscription_reference_invalid');
                }

                $subscription = TenantSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->where('billing_provider', $provider)
                    ->where('provider_account_id', $providerAccountId)
                    ->where('provider_subscription_id', $providerSubscriptionId)
                    ->first();

                if ($subscription === null) {
                    throw new RuntimeException('billing_provider_subscription_unmapped');
                }

                unset($payload['provider_subscription_id']);
                $payload['subscription_id'] = $subscription->id;

                if (in_array($eventType, ['subscription.renewed', 'subscription.reactivated'], true)) {
                    $providerInvoiceId = $payload['provider_invoice_id'] ?? null;
                    if (! is_string($providerInvoiceId) || trim($providerInvoiceId) === '' || strlen($providerInvoiceId) > 180) {
                        throw new InvalidArgumentException('billing_provider_invoice_reference_invalid');
                    }

                    $invoice = TenantBillingInvoice::query()
                        ->where('tenant_id', $tenantId)
                        ->where('provider', $provider)
                        ->where('provider_account_id', $providerAccountId)
                        ->where('provider_invoice_id', $providerInvoiceId)
                        ->where('tenant_subscription_id', $subscription->id)
                        ->first();

                    if ($invoice === null) {
                        throw new RuntimeException('billing_provider_invoice_unmapped');
                    }

                    unset($payload['provider_invoice_id']);
                    $payload['settled_invoice_id'] = $invoice->id;
                }
            }

            return $payload;
        });
    }
}

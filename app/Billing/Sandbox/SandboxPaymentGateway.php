<?php

namespace App\Billing\Sandbox;

use App\Contracts\Billing\PaymentGateway;
use App\Models\TenantBillingInvoice;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Tenancy\TenantBillingWebhookGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

class SandboxPaymentGateway implements PaymentGateway
{
    public function driver(): string
    {
        return 'sandbox';
    }

    public function assertUsable(): void
    {
        if (! filter_var(config('billing.sandbox.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('billing_sandbox_disabled');
        }

        if (app()->environment('production')
            && ! filter_var(config('billing.sandbox.allow_in_production'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('billing_sandbox_blocked_in_production');
        }
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    public function startCheckout(
        TenantSubscription $subscription,
        TenantBillingInvoice $invoice,
        User $actor,
        array $billingDetails,
    ): string {
        $this->assertUsable();

        return URL::temporarySignedRoute(
            'billing.sandbox.checkout.show',
            now()->addHours(2),
            [
                'invoice' => $invoice->id,
                'tenant' => $subscription->tenant_id,
            ],
        );
    }

    public function approve(TenantBillingInvoice $invoice): void
    {
        $this->assertUsable();
        $this->dispatchWebhook($invoice, 'invoice.payment_succeeded', [
            'provider_invoice_id' => $invoice->provider_invoice_id,
            'payment_id' => 'pay_sandbox_'.Str::lower(Str::random(16)),
            'amount_minor' => $invoice->amount_due_minor,
            'currency' => $invoice->currency,
        ]);
        Artisan::call('billing:webhooks:reconcile', ['--limit' => 50]);
    }

    public function decline(TenantBillingInvoice $invoice): void
    {
        $this->assertUsable();
        $this->dispatchWebhook($invoice, 'invoice.payment_failed', [
            'provider_invoice_id' => $invoice->provider_invoice_id,
            'failure_code' => 'sandbox_declined',
        ]);
        Artisan::call('billing:webhooks:reconcile', ['--limit' => 50]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function dispatchWebhook(TenantBillingInvoice $invoice, string $eventType, array $normalized): void
    {
        if ($invoice->provider !== 'sandbox'
            || ! is_string($invoice->provider_account_id)
            || ! is_string($invoice->provider_invoice_id)) {
            throw new RuntimeException('billing_sandbox_invoice_provider_invalid');
        }

        $body = json_encode([
            'provider_account_id' => $invoice->provider_account_id,
            'provider_event_id' => 'evt_sandbox_'.Str::lower(Str::random(20)),
            'event_type' => $eventType,
            'normalized' => $normalized,
            'occurred_at' => now('UTC')->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $timestamp = (string) now('UTC')->timestamp;
        $secret = (string) config('billing.sandbox.signing_secret');
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        app(TenantBillingWebhookGateway::class)->ingestConfigured('sandbox', $body, [
            'X-Sandbox-Signature' => $signature,
            'X-Sandbox-Timestamp' => $timestamp,
        ]);
    }
}

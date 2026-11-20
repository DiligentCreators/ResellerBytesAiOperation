<?php

namespace App\Billing\Kelviq;

use App\Contracts\Billing\PaymentGateway;
use App\Models\Plan;
use App\Models\TenantBillingInvoice;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KelviqPaymentGateway implements PaymentGateway
{
    public function __construct(private readonly KelviqClient $client) {}

    public function driver(): string
    {
        return 'kelviq';
    }

    public function assertUsable(): void
    {
        $mode = (string) config('billing.kelviq.mode', 'sandbox');
        $apiKey = (string) config('billing.kelviq.api_key');
        $webhookSecret = (string) config('billing.kelviq.webhook_secret');

        if ($mode === 'live' && ($apiKey === '' || $webhookSecret === '')) {
            throw new RuntimeException('billing_kelviq_live_credentials_required');
        }

        if ($apiKey === '') {
            throw new RuntimeException('billing_kelviq_api_key_missing');
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

        $plan = $subscription->plan_id !== null
            ? Plan::query()->find($subscription->plan_id)
            : null;

        if ($plan === null) {
            throw new RuntimeException('billing_kelviq_plan_missing');
        }

        $period = $this->inferPeriod($subscription, $plan);
        $planIdentifier = $plan->kelviqPlanIdentifierForPeriod($period);
        if ($planIdentifier === null || $planIdentifier === '') {
            throw new RuntimeException('billing_kelviq_plan_identifier_missing');
        }

        $customerId = is_string($subscription->provider_account_id) && $subscription->provider_account_id !== ''
            ? $subscription->provider_account_id
            : 'acct_kelviq_'.str_replace('-', '', $subscription->tenant_id);

        $email = is_string($billingDetails['billing_email'] ?? null)
            ? (string) $billingDetails['billing_email']
            : $actor->email;
        $name = is_string($billingDetails['company_name'] ?? null)
            ? (string) $billingDetails['company_name']
            : $actor->name;

        try {
            $this->client->createCustomer([
                'customerId' => $customerId,
                'name' => $name,
                'email' => $email,
            ]);
        } catch (\Throwable $exception) {
            // TODO(kelviq): confirm with Kelviq whether duplicate customerId returns 4xx we can ignore.
            Log::info('Kelviq customer create skipped or failed; continuing to checkout.', [
                'reason_code' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'kelviq_customer_create_failed',
                'exception_class' => $exception::class,
                'tenant_id' => $subscription->tenant_id,
            ]);
        }

        $successUrl = route('billing.checkout.return', [
            'tenant' => $subscription->tenant_id,
            'invoice' => $invoice->id,
        ], absolute: true);
        $cancelUrl = route('billing.settings.show', ['tenant' => $subscription->tenant_id], absolute: true);

        $checkout = $this->client->createCheckout([
            'planIdentifier' => $planIdentifier,
            'chargePeriod' => $period === 'yearly' ? 'YEARLY' : 'MONTHLY',
            'customerId' => $customerId,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'email' => $email,
            'lockEmail' => true,
            'defaultBillingCountry' => strtoupper((string) ($billingDetails['country'] ?? 'US')),
            'metadata' => [
                'tenant_id' => $subscription->tenant_id,
                'plan' => $subscription->plan_code,
                'period' => $period,
                'local_invoice_id' => (string) $invoice->id,
                'provider_invoice_id' => (string) $invoice->provider_invoice_id,
                'local_subscription_id' => (string) $subscription->id,
                'checkout_kind' => $subscription->status === 'incomplete' ? 'plan_change' : 'checkout',
            ],
            // TODO(kelviq): confirm trialPeriod units (days vs ISO duration) before enabling.
        ]);

        $details = is_array($invoice->billing_details) ? $invoice->billing_details : [];
        $details['kelviq_checkout_session_id'] = $checkout['checkoutSessionId'];
        $invoice->forceFill(['billing_details' => $details])->save();

        return $checkout['checkoutUrl'];
    }

    private function inferPeriod(TenantSubscription $subscription, Plan $plan): string
    {
        if ($subscription->unit_amount_minor === $plan->price_yearly_minor) {
            return 'yearly';
        }

        return 'monthly';
    }
}

<?php

namespace App\Billing\Kelviq;

use App\Contracts\Billing\PaymentGateway;
use App\Models\Plan;
use App\Models\TenantBillingInvoice;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\PlanChangeClassifier;
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
        $settings = app(BillingSettings::class);
        $mode = $settings->kelviqMode();
        $apiKey = $settings->kelviqApiKey();
        $webhookSecret = $settings->kelviqWebhookSecret();

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

        $period = app(PlanChangeClassifier::class)->chargePeriodFor($subscription);
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

        $checkoutKind = $this->checkoutKind($subscription, $invoice);
        $payload = [
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
                'checkout_kind' => $checkoutKind,
            ],
        ];

        // trialPeriod is integer days (>= 1) and overrides the Kelviq plan trial.
        // Only set for explicit trial checkouts — never for paid CheckoutService::begin.
        $trialDays = $this->trialPeriodDays($subscription, $invoice, $checkoutKind);
        if ($trialDays !== null) {
            $payload['trialPeriod'] = $trialDays;
        }

        $checkout = $this->client->createCheckout($payload);

        $details = is_array($invoice->billing_details) ? $invoice->billing_details : [];
        $details['kelviq_checkout_session_id'] = $checkout['checkoutSessionId'];
        $invoice->forceFill(['billing_details' => $details])->save();

        return $checkout['checkoutUrl'];
    }

    /**
     * @return 'trial'|'plan_change'|'checkout'
     */
    private function checkoutKind(TenantSubscription $subscription, TenantBillingInvoice $invoice): string
    {
        $details = is_array($invoice->billing_details) ? $invoice->billing_details : [];
        if (($details['checkout_kind'] ?? null) === 'trial'
            || ($subscription->status === 'trialing' && (int) $invoice->amount_due_minor === 0)) {
            return 'trial';
        }

        if ($subscription->status === 'incomplete' && $subscription->replaces_subscription_id !== null) {
            return 'plan_change';
        }

        return 'checkout';
    }

    private function trialPeriodDays(
        TenantSubscription $subscription,
        TenantBillingInvoice $invoice,
        string $checkoutKind,
    ): ?int {
        if ($checkoutKind !== 'trial') {
            return null;
        }

        $details = is_array($invoice->billing_details) ? $invoice->billing_details : [];
        $fromDetails = filter_var($details['trial_days'] ?? null, FILTER_VALIDATE_INT);
        if ($fromDetails !== false && $fromDetails >= 1) {
            return $fromDetails;
        }

        if ($subscription->trial_ends_at !== null && $subscription->current_period_starts_at !== null) {
            $days = (int) $subscription->current_period_starts_at->diffInDays($subscription->trial_ends_at);
            if ($days >= 1) {
                return $days;
            }
        }

        return null;
    }
}

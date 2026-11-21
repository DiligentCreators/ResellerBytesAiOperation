<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingProviderAccount;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Tenancy\TenantRoleAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CheckoutService
{
    /**
     * @param  array<string, mixed>  $billingDetails
     * @return array{subscription: TenantSubscription, invoice: TenantBillingInvoice, checkout_url: string}
     */
    public function begin(
        string $tenantId,
        User $actor,
        Plan $plan,
        string $billingPeriod,
        array $billingDetails,
    ): array {
        if (! in_array($billingPeriod, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('billing_period_invalid');
        }

        if (! $plan->isCheckoutable()) {
            throw new InvalidArgumentException('billing_plan_not_checkoutable');
        }

        if (! $plan->intervalIsActive($billingPeriod)) {
            throw new InvalidArgumentException('billing_interval_inactive');
        }

        $this->validateBillingDetails($billingDetails);

        $gateway = app(PaymentGatewayManager::class)->driver();

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            (int) $actor->id,
            fn (): array => DB::transaction(function () use ($tenantId, $actor, $plan, $billingPeriod, $billingDetails, $gateway): array {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');

                $current = TenantSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
                    ->lockForUpdate()
                    ->first();

                if ($current !== null) {
                    throw new RuntimeException('billing_subscription_already_current');
                }

                // Abandoned paid checkouts leave provisional incomplete rows; clear them
                // so a retry can start without blocking on the unique-current index.
                TenantSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'incomplete')
                    ->whereNull('replaces_subscription_id')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (TenantSubscription $abandoned): void {
                        $abandoned->forceFill([
                            'status' => 'canceled',
                            'canceled_at' => CarbonImmutable::now('UTC'),
                        ])->save();
                    });

                $provider = $gateway->driver();
                $providerAccountId = 'acct_'.$provider.'_'.str_replace('-', '', $tenantId);
                $providerAccount = TenantBillingProviderAccount::query()
                    ->where('provider', $provider)
                    ->where('provider_account_id', $providerAccountId)
                    ->lockForUpdate()
                    ->first();

                if ($providerAccount === null) {
                    $providerAccount = TenantBillingProviderAccount::query()->create([
                        'tenant_id' => $tenantId,
                        'provider' => $provider,
                        'provider_account_id' => $providerAccountId,
                        'status' => 'active',
                        'verified_at' => CarbonImmutable::now('UTC'),
                        'created_by_user_id' => $actor->id,
                    ]);
                } elseif ($providerAccount->tenant_id !== $tenantId) {
                    throw new RuntimeException('billing_provider_account_already_bound');
                } else {
                    $providerAccount->forceFill([
                        'status' => 'active',
                        'verified_at' => $providerAccount->verified_at ?? CarbonImmutable::now('UTC'),
                    ])->save();
                }

                $now = CarbonImmutable::now('UTC');
                // RB-2: Paid checkout always charges the first period up front. Trials are a
                // separate TrialService action and must never shorten this period or send
                // trialPeriod to Kelviq from this path.
                $periodEnds = $billingPeriod === 'yearly' ? $now->addYear() : $now->addMonth();

                $amount = $plan->priceForPeriod($billingPeriod);
                $providerSubscriptionId = $provider === 'kelviq'
                    ? null
                    : 'sub_'.$provider.'_'.Str::lower(Str::random(18));
                $providerInvoiceId = 'inv_'.$provider.'_'.Str::lower(Str::random(18));

                $subscription = TenantSubscription::query()->create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                    'billing_mode' => 'automated',
                    // incomplete until first payment webhook succeeds (not a free trial).
                    'status' => 'incomplete',
                    'billing_provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_subscription_id' => $providerSubscriptionId,
                    'currency' => $plan->currency,
                    'unit_amount_minor' => $amount,
                    'charge_period' => $billingPeriod,
                    'entitlements' => $plan->entitlements,
                    'cancel_at_period_end' => false,
                    'current_period_starts_at' => $now,
                    'current_period_ends_at' => $periodEnds,
                    'created_by_user_id' => $actor->id,
                ]);

                $lineItems = [[
                    'description' => $plan->name.' ('.$billingPeriod.')',
                    'quantity' => 1,
                    'unit_amount_minor' => $amount,
                    'amount_minor' => $amount,
                ]];

                $invoice = TenantBillingInvoice::query()->create([
                    'tenant_id' => $tenantId,
                    'tenant_subscription_id' => $subscription->id,
                    'invoice_number' => app(InvoiceNumberGenerator::class)->next(),
                    'provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_invoice_id' => $providerInvoiceId,
                    'status' => 'open',
                    'currency' => $plan->currency,
                    'subtotal_minor' => $amount,
                    'tax_minor' => 0,
                    'amount_due_minor' => $amount,
                    'amount_paid_minor' => 0,
                    'line_items' => $lineItems,
                    'billing_details' => $billingDetails,
                    'issued_at' => $now,
                    'due_at' => $now->addDays(7),
                    'service_period_starts_at' => $now,
                    'service_period_ends_at' => $periodEnds,
                ]);

                $this->persistTenantBillingDetails($tenantId, $billingDetails);

                $checkoutUrl = $gateway->startCheckout($subscription, $invoice, $actor, $billingDetails);

                return [
                    'subscription' => $subscription,
                    'invoice' => $invoice,
                    'checkout_url' => $checkoutUrl,
                ];
            }, 3),
        );
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    public function validateBillingDetails(array $billingDetails): void
    {
        foreach (['company_name', 'billing_email', 'country', 'address_line1'] as $required) {
            $value = $billingDetails[$required] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('billing_details_incomplete');
            }
        }

        if (! filter_var($billingDetails['billing_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('billing_email_invalid');
        }

        if (! preg_match('/^[A-Z]{2}$/', (string) $billingDetails['country'])) {
            throw new InvalidArgumentException('billing_country_invalid');
        }
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    public function persistTenantBillingDetails(string $tenantId, array $billingDetails): void
    {
        $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
        $settings = (array) $tenant->settings;
        $settings['billing'] = [
            'company_name' => $billingDetails['company_name'],
            'billing_email' => $billingDetails['billing_email'],
            'country' => $billingDetails['country'],
            'address_line1' => $billingDetails['address_line1'],
            'address_line2' => $billingDetails['address_line2'] ?? null,
            'city' => $billingDetails['city'] ?? null,
            'state' => $billingDetails['state'] ?? null,
            'postal_code' => $billingDetails['postal_code'] ?? null,
            'tax_id' => $billingDetails['tax_id'] ?? null,
        ];
        $tenant->forceFill(['settings' => $settings])->save();
    }
}

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

/**
 * Explicit trial enrollment — never used by paid CheckoutService::begin.
 *
 * Card-required trials create a zero-amount open invoice solely to host the
 * gateway checkout session (Kelviq trialPeriod / sandbox card hold). Payment
 * webhooks require amount >= 1, so that path will not flip trialing → active;
 * conversion happens on a later paid invoice, and ExpireBillingTrialsCommand
 * expires unpaid trials past trial_ends_at.
 */
class TrialService
{
    public function __construct(
        private readonly TrialPolicy $trialPolicy,
        private readonly PaymentGatewayManager $gateways,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * @param  array<string, mixed>  $billingDetails
     * @return array{
     *     subscription: TenantSubscription,
     *     invoice: TenantBillingInvoice|null,
     *     checkout_url: string|null,
     *     card_required: bool
     * }
     */
    public function begin(
        string $tenantId,
        User $actor,
        Plan $plan,
        string $billingPeriod,
        array $billingDetails = [],
    ): array {
        if (! in_array($billingPeriod, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('billing_period_invalid');
        }

        if (! $plan->isCheckoutable()) {
            throw new InvalidArgumentException(
                $plan->hasPrices() ? 'billing_plan_not_checkoutable' : 'billing_plan_price_missing',
            );
        }

        if (! $plan->intervalIsActive($billingPeriod)) {
            throw new InvalidArgumentException('billing_interval_inactive');
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        $trialDays = $this->trialPolicy->trialDaysFor($tenant, $plan);
        if ($trialDays < 1) {
            throw new InvalidArgumentException('billing_trial_not_eligible');
        }

        $cardRequired = $this->trialPolicy->cardRequired();
        if ($cardRequired) {
            $this->checkout->validateBillingDetails($billingDetails);
        }

        $gateway = $cardRequired ? $this->gateways->driver() : null;

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            (int) $actor->id,
            fn (): array => DB::transaction(function () use (
                $tenantId,
                $actor,
                $plan,
                $billingPeriod,
                $billingDetails,
                $gateway,
                $trialDays,
                $cardRequired,
            ): array {
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

                $now = CarbonImmutable::now('UTC');
                $trialEnds = $now->addDays($trialDays);
                $amount = $plan->priceForPeriod($billingPeriod);

                $provider = $gateway?->driver();
                $providerAccountId = null;

                if ($cardRequired && $gateway !== null && $provider !== null) {
                    $providerAccountId = 'acct_'.$provider.'_'.str_replace('-', '', $tenantId);
                    $providerAccount = TenantBillingProviderAccount::query()
                        ->where('provider', $provider)
                        ->where('provider_account_id', $providerAccountId)
                        ->lockForUpdate()
                        ->first();

                    if ($providerAccount === null) {
                        TenantBillingProviderAccount::query()->create([
                            'tenant_id' => $tenantId,
                            'provider' => $provider,
                            'provider_account_id' => $providerAccountId,
                            'status' => 'active',
                            'verified_at' => $now,
                            'created_by_user_id' => $actor->id,
                        ]);
                    } elseif ($providerAccount->tenant_id !== $tenantId) {
                        throw new RuntimeException('billing_provider_account_already_bound');
                    }
                }

                $subscription = TenantSubscription::query()->create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                    'billing_mode' => 'automated',
                    'status' => 'trialing',
                    'billing_provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_subscription_id' => null,
                    'currency' => $plan->currency,
                    'unit_amount_minor' => $amount,
                    'charge_period' => $billingPeriod,
                    'entitlements' => $plan->entitlements,
                    'cancel_at_period_end' => false,
                    'current_period_starts_at' => $now,
                    'current_period_ends_at' => $trialEnds,
                    'trial_ends_at' => $trialEnds,
                    'created_by_user_id' => $actor->id,
                ]);

                if (! $cardRequired || $gateway === null || $provider === null || $providerAccountId === null) {
                    return [
                        'subscription' => $subscription,
                        'invoice' => null,
                        'checkout_url' => null,
                        'card_required' => false,
                    ];
                }

                $providerInvoiceId = 'inv_'.$provider.'_'.Str::lower(Str::random(18));
                $invoice = TenantBillingInvoice::query()->create([
                    'tenant_id' => $tenantId,
                    'tenant_subscription_id' => $subscription->id,
                    'invoice_number' => app(InvoiceNumberGenerator::class)->next(),
                    'provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_invoice_id' => $providerInvoiceId,
                    'status' => 'open',
                    'currency' => $plan->currency,
                    'subtotal_minor' => 0,
                    'tax_minor' => 0,
                    'amount_due_minor' => 0,
                    'amount_paid_minor' => 0,
                    'line_items' => [[
                        'description' => $plan->name.' trial ('.$billingPeriod.')',
                        'quantity' => 1,
                        'unit_amount_minor' => 0,
                        'amount_minor' => 0,
                    ]],
                    'billing_details' => [
                        ...$billingDetails,
                        'checkout_kind' => 'trial',
                        'trial_days' => $trialDays,
                    ],
                    'issued_at' => $now,
                    'due_at' => $trialEnds,
                    'service_period_starts_at' => $now,
                    'service_period_ends_at' => $trialEnds,
                ]);

                $this->checkout->persistTenantBillingDetails($tenantId, $billingDetails);
                $checkoutUrl = $gateway->startCheckout($subscription, $invoice, $actor, $billingDetails);

                return [
                    'subscription' => $subscription,
                    'invoice' => $invoice,
                    'checkout_url' => $checkoutUrl,
                    'card_required' => true,
                ];
            }, 3),
        );
    }
}

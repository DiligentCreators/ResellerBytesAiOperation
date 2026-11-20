<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\Tenancy\TenantBillingOutbox;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Tenancy\TenantRoleAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SubscriptionManagementService
{
    public function cancelAtPeriodEnd(string $tenantId, int $subscriptionId, User $actor): TenantSubscription
    {
        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            (int) $actor->id,
            fn (): TenantSubscription => DB::transaction(function () use ($tenantId, $subscriptionId, $actor): TenantSubscription {
                app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');
                $subscription = $this->lockCurrent($tenantId, $subscriptionId);

                if (! in_array($subscription->status, ['trialing', 'active', 'past_due'], true)) {
                    throw new InvalidArgumentException('billing_subscription_cancel_not_allowed');
                }

                $subscription->forceFill(['cancel_at_period_end' => true])->save();
                $this->audit($tenantId, $actor->id, $subscription, 'tenant_subscription.cancel_at_period_end_set');

                return $subscription->refresh();
            }, 3),
        );
    }

    public function resume(string $tenantId, int $subscriptionId, User $actor): TenantSubscription
    {
        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            (int) $actor->id,
            fn (): TenantSubscription => DB::transaction(function () use ($tenantId, $subscriptionId, $actor): TenantSubscription {
                app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');
                $subscription = $this->lockCurrent($tenantId, $subscriptionId);

                if (! $subscription->cancel_at_period_end) {
                    return $subscription;
                }

                $subscription->forceFill(['cancel_at_period_end' => false])->save();
                $this->audit($tenantId, $actor->id, $subscription, 'tenant_subscription.cancel_at_period_end_cleared');

                return $subscription->refresh();
            }, 3),
        );
    }

    /**
     * Plan changes keep the current subscription active until the new plan's
     * payment is confirmed via webhook, then switch atomically. The pending
     * subscription uses status `incomplete` (outside the one-current unique index).
     *
     * @param  array<string, mixed>  $billingDetails
     * @return array{subscription: TenantSubscription, invoice: TenantBillingInvoice, checkout_url: string, current_subscription: TenantSubscription}
     */
    public function changePlan(
        string $tenantId,
        int $subscriptionId,
        User $actor,
        Plan $plan,
        string $billingPeriod,
        array $billingDetails,
    ): array {
        if (! in_array($billingPeriod, ['monthly', 'yearly'], true) || ! $plan->is_active || $plan->is_contact_sales) {
            throw new InvalidArgumentException('billing_plan_change_invalid');
        }

        app(CheckoutService::class)->validateBillingDetails($billingDetails);
        $gateway = app(PaymentGatewayManager::class)->driver();

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            (int) $actor->id,
            fn (): array => DB::transaction(function () use ($tenantId, $subscriptionId, $actor, $plan, $billingPeriod, $billingDetails, $gateway): array {
                app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $current = $this->lockCurrent($tenantId, $subscriptionId);

                if (! in_array($current->status, ['trialing', 'active', 'past_due'], true)) {
                    throw new InvalidArgumentException('billing_plan_change_status_invalid');
                }

                if ($current->plan_code === $plan->code && $current->unit_amount_minor === $plan->priceForPeriod($billingPeriod)) {
                    throw new InvalidArgumentException('billing_plan_unchanged');
                }

                $pending = TenantSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'incomplete')
                    ->lockForUpdate()
                    ->first();

                if ($pending !== null) {
                    throw new RuntimeException('billing_plan_change_already_pending');
                }

                $provider = $gateway->driver();
                $providerAccountId = $current->provider_account_id ?? ('acct_'.$provider.'_'.str_replace('-', '', $tenantId));
                $now = CarbonImmutable::now('UTC');
                $periodEnds = $billingPeriod === 'yearly' ? $now->addYear() : $now->addMonth();
                $amount = $plan->priceForPeriod($billingPeriod);
                $providerSubscriptionId = $provider === 'kelviq'
                    ? null
                    : 'sub_'.$provider.'_'.Str::lower(Str::random(18));

                $subscription = TenantSubscription::query()->create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                    'billing_mode' => 'automated',
                    'status' => 'incomplete',
                    'billing_provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_subscription_id' => $providerSubscriptionId,
                    'currency' => $plan->currency,
                    'unit_amount_minor' => $amount,
                    'entitlements' => $plan->entitlements,
                    'cancel_at_period_end' => false,
                    'replaces_subscription_id' => $current->id,
                    'current_period_starts_at' => $now,
                    'current_period_ends_at' => $periodEnds,
                    'created_by_user_id' => $actor->id,
                ]);

                $invoice = TenantBillingInvoice::query()->create([
                    'tenant_id' => $tenantId,
                    'tenant_subscription_id' => $subscription->id,
                    'invoice_number' => app(InvoiceNumberGenerator::class)->next(),
                    'provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'provider_invoice_id' => 'inv_'.$provider.'_'.Str::lower(Str::random(18)),
                    'status' => 'open',
                    'currency' => $plan->currency,
                    'subtotal_minor' => $amount,
                    'tax_minor' => 0,
                    'amount_due_minor' => $amount,
                    'amount_paid_minor' => 0,
                    'line_items' => [[
                        'description' => 'Plan change to '.$plan->name.' ('.$billingPeriod.')',
                        'quantity' => 1,
                        'unit_amount_minor' => $amount,
                        'amount_minor' => $amount,
                    ]],
                    'billing_details' => $billingDetails,
                    'issued_at' => $now,
                    'due_at' => $now->addDays(7),
                ]);

                $checkoutUrl = $gateway->startCheckout($subscription, $invoice, $actor, $billingDetails);

                $this->audit($tenantId, $actor->id, $subscription, 'tenant_subscription.plan_change_started');

                return [
                    'subscription' => $subscription,
                    'invoice' => $invoice,
                    'checkout_url' => $checkoutUrl,
                    'current_subscription' => $current->refresh(),
                ];
            }, 3),
        );
    }

    private function lockCurrent(string $tenantId, int $subscriptionId): TenantSubscription
    {
        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($subscriptionId)
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($subscription->status, ['trialing', 'active', 'past_due', 'suspended'], true)) {
            throw new RuntimeException('billing_subscription_not_current');
        }

        return $subscription;
    }

    private function audit(string $tenantId, int $actorId, TenantSubscription $subscription, string $eventType): void
    {
        app(AuditEventWriter::class)->append([
            'actor_type' => 'user',
            'actor_id' => (string) $actorId,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'subject_type' => 'tenant_subscription',
            'subject_id' => (string) $subscription->id,
            'metadata' => [
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'status' => $subscription->status,
                'replaces_subscription_id' => $subscription->replaces_subscription_id,
            ],
            'occurred_at' => now('UTC'),
        ]);

        app(TenantBillingOutbox::class)->enqueue(
            $tenantId,
            $eventType.':'.$subscription->id.':'.Str::uuid(),
            $eventType,
            'tenant_subscription',
            $subscription->id,
            [
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'replaces_subscription_id' => $subscription->replaces_subscription_id,
            ],
        );
    }
}

<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\Tenancy\TenantBillingOutbox;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Tenancy\TenantRoleAuthorization;
use App\Services\Tenancy\TenantSubscriptionLifecycle;
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
     * payment is confirmed via webhook (upgrades), or until period end
     * (scheduled downgrades). Immediate upgrades use day-based proration.
     *
     * @param  array<string, mixed>  $billingDetails
     * @return array{
     *     mode: 'upgrade'|'downgrade'|'zero_upgrade',
     *     subscription: TenantSubscription,
     *     invoice: TenantBillingInvoice|null,
     *     checkout_url: string|null,
     *     current_subscription: TenantSubscription,
     *     proration: array<string, mixed>|null,
     *     scheduled_change: array<string, mixed>|null
     * }
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

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            (int) $actor->id,
            fn (): array => DB::transaction(function () use ($tenantId, $subscriptionId, $actor, $plan, $billingPeriod, $billingDetails): array {
                app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $current = $this->lockCurrent($tenantId, $subscriptionId);
                $current->loadMissing('plan');

                if (! in_array($current->status, ['trialing', 'active', 'past_due'], true)) {
                    throw new InvalidArgumentException('billing_plan_change_status_invalid');
                }

                $targetAmount = $plan->priceForPeriod($billingPeriod);
                if ($current->plan_code === $plan->code && $current->unit_amount_minor === $targetAmount) {
                    throw new InvalidArgumentException('billing_plan_unchanged');
                }

                $classifier = app(PlanChangeClassifier::class);
                if ($classifier->isDowngrade($current, $plan, $billingPeriod)) {
                    return $this->scheduleDowngrade($tenantId, $actor, $current, $plan, $billingPeriod);
                }

                return $this->beginImmediateUpgrade($tenantId, $actor, $current, $plan, $billingPeriod, $billingDetails, $targetAmount);
            }, 3),
        );
    }

    public function cancelScheduledChange(string $tenantId, int $subscriptionId, User $actor): TenantSubscription
    {
        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            (int) $actor->id,
            fn (): TenantSubscription => DB::transaction(function () use ($tenantId, $subscriptionId, $actor): TenantSubscription {
                app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');
                $subscription = $this->lockCurrent($tenantId, $subscriptionId);

                if ($subscription->scheduled_plan_id === null) {
                    return $subscription;
                }

                $subscription->forceFill([
                    'scheduled_plan_id' => null,
                    'scheduled_charge_period' => null,
                    'scheduled_change_at' => null,
                ])->save();

                $this->audit($tenantId, $actor->id, $subscription, 'tenant_subscription.scheduled_plan_change_canceled');

                return $subscription->refresh();
            }, 3),
        );
    }

    /**
     * Apply due scheduled plan changes. Idempotent under row locks.
     *
     * @return array{applied: int, skipped_cancel_at_period_end: int, skipped_not_due: int}
     */
    public function applyDueScheduledChanges(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $now = CarbonImmutable::now('UTC');
        $applied = 0;
        $skippedCancel = 0;
        $skippedNotDue = 0;

        $ids = TenantSubscription::query()
            ->whereNotNull('scheduled_plan_id')
            ->whereNotNull('scheduled_change_at')
            ->where('scheduled_change_at', '<=', $now)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $subscriptionId) {
            $result = DB::transaction(function () use ($subscriptionId, $now): string {
                $subscription = TenantSubscription::query()
                    ->whereKey($subscriptionId)
                    ->lockForUpdate()
                    ->first();

                if ($subscription === null
                    || $subscription->scheduled_plan_id === null
                    || $subscription->scheduled_change_at === null) {
                    return 'skip_not_due';
                }

                if ($subscription->scheduled_change_at->greaterThan($now)) {
                    return 'skip_not_due';
                }

                if (! in_array($subscription->status, ['trialing', 'active', 'past_due', 'suspended'], true)) {
                    $this->clearScheduledChange($subscription);

                    return 'skip_not_due';
                }

                if ($subscription->cancel_at_period_end) {
                    // Cancellation at period end wins over a pending plan change.
                    $this->clearScheduledChange($subscription);
                    $this->systemAudit(
                        $subscription->tenant_id,
                        $subscription,
                        'tenant_subscription.scheduled_plan_change_skipped_cancel_at_period_end',
                    );

                    return 'skip_cancel';
                }

                $plan = Plan::query()->whereKey($subscription->scheduled_plan_id)->first();
                $period = $subscription->scheduled_charge_period;
                if ($plan === null || ! in_array($period, ['monthly', 'yearly'], true) || ! $plan->is_active) {
                    $this->clearScheduledChange($subscription);

                    return 'skip_not_due';
                }

                if (! $this->applyScheduledChangeToSubscription($subscription, $plan, $period, $now)) {
                    return 'skip_not_due';
                }

                return 'applied';
            }, 3);

            match ($result) {
                'applied' => $applied++,
                'skip_cancel' => $skippedCancel++,
                default => $skippedNotDue++,
            };
        }

        return [
            'applied' => $applied,
            'skipped_cancel_at_period_end' => $skippedCancel,
            'skipped_not_due' => $skippedNotDue,
        ];
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     * @return array{
     *     mode: 'upgrade'|'zero_upgrade',
     *     subscription: TenantSubscription,
     *     invoice: TenantBillingInvoice,
     *     checkout_url: string|null,
     *     current_subscription: TenantSubscription,
     *     proration: array<string, mixed>,
     *     scheduled_change: null
     * }
     */
    private function beginImmediateUpgrade(
        string $tenantId,
        User $actor,
        TenantSubscription $current,
        Plan $plan,
        string $billingPeriod,
        array $billingDetails,
        int $targetAmount,
    ): array {
        $pending = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'incomplete')
            ->lockForUpdate()
            ->first();

        if ($pending !== null) {
            throw new RuntimeException('billing_plan_change_already_pending');
        }

        // Immediate upgrades clear any pending scheduled downgrade.
        $this->clearScheduledChange($current);

        $gateway = app(PaymentGatewayManager::class)->driver();
        $provider = $gateway->driver();
        $providerAccountId = $current->provider_account_id ?? ('acct_'.$provider.'_'.str_replace('-', '', $tenantId));
        $now = CarbonImmutable::now('UTC');
        $periodEnds = $billingPeriod === 'yearly' ? $now->addYear() : $now->addMonth();

        $proration = app(ProrationCalculator::class)->forUpgrade(
            $targetAmount,
            (int) $current->unit_amount_minor,
            $current->current_period_starts_at,
            $current->current_period_ends_at,
            $plan->currency,
            $now,
        );

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
            'unit_amount_minor' => $targetAmount,
            'entitlements' => $plan->entitlements,
            'cancel_at_period_end' => false,
            'replaces_subscription_id' => $current->id,
            'current_period_starts_at' => $now,
            'current_period_ends_at' => $periodEnds,
            'created_by_user_id' => $actor->id,
        ]);

        $periodLabel = $this->formatPeriodRange($now, $periodEnds);
        $oldPlanName = $current->plan->name ?? $current->plan_code;
        $lineItems = [
            [
                'description' => $plan->name.' ('.$billingPeriod.'), '.$periodLabel,
                'quantity' => 1,
                'unit_amount_minor' => $proration->newPriceMinor,
                'amount_minor' => $proration->newPriceMinor,
            ],
        ];
        if ($proration->creditMinor > 0) {
            $lineItems[] = [
                'description' => 'Credit for unused time on '.$oldPlanName.' ('.$proration->remainingDays.' days)',
                'quantity' => 1,
                'unit_amount_minor' => -$proration->creditMinor,
                'amount_minor' => -$proration->creditMinor,
            ];
        }

        $invoice = TenantBillingInvoice::query()->create([
            'tenant_id' => $tenantId,
            'tenant_subscription_id' => $subscription->id,
            'invoice_number' => app(InvoiceNumberGenerator::class)->next(),
            'provider' => $provider,
            'provider_account_id' => $providerAccountId,
            'provider_invoice_id' => 'inv_'.$provider.'_'.Str::lower(Str::random(18)),
            'status' => 'open',
            'currency' => $plan->currency,
            'subtotal_minor' => $proration->newPriceMinor,
            'tax_minor' => 0,
            'amount_due_minor' => $proration->totalDueMinor,
            'amount_paid_minor' => 0,
            'line_items' => $lineItems,
            'billing_details' => $billingDetails,
            'issued_at' => $now,
            'due_at' => $now->addDays(7),
            'service_period_starts_at' => $now,
            'service_period_ends_at' => $periodEnds,
        ]);

        $this->audit($tenantId, $actor->id, $subscription, 'tenant_subscription.plan_change_started', [
            'proration' => $proration->toArray(),
            'mode' => $proration->totalDueMinor === 0 ? 'zero_upgrade' : 'upgrade',
        ]);

        if ($proration->totalDueMinor === 0) {
            $this->settleZeroDueUpgrade($tenantId, $subscription, $invoice, $current);

            return [
                'mode' => 'zero_upgrade',
                'subscription' => $subscription->refresh(),
                'invoice' => $invoice->refresh(),
                'checkout_url' => null,
                'current_subscription' => $current->refresh(),
                'proration' => $proration->toArray(),
                'scheduled_change' => null,
            ];
        }

        $checkoutUrl = $gateway->startCheckout($subscription, $invoice, $actor, $billingDetails);

        return [
            'mode' => 'upgrade',
            'subscription' => $subscription,
            'invoice' => $invoice,
            'checkout_url' => $checkoutUrl,
            'current_subscription' => $current->refresh(),
            'proration' => $proration->toArray(),
            'scheduled_change' => null,
        ];
    }

    /**
     * @return array{
     *     mode: 'downgrade',
     *     subscription: TenantSubscription,
     *     invoice: null,
     *     checkout_url: null,
     *     current_subscription: TenantSubscription,
     *     proration: null,
     *     scheduled_change: array<string, mixed>
     * }
     */
    private function scheduleDowngrade(
        string $tenantId,
        User $actor,
        TenantSubscription $current,
        Plan $plan,
        string $billingPeriod,
    ): array {
        $changeAt = CarbonImmutable::parse($current->current_period_ends_at)->utc();

        $current->forceFill([
            'scheduled_plan_id' => $plan->id,
            'scheduled_charge_period' => $billingPeriod,
            'scheduled_change_at' => $changeAt,
        ])->save();

        $this->audit($tenantId, $actor->id, $current, 'tenant_subscription.scheduled_plan_change_set', [
            'scheduled_plan_id' => $plan->id,
            'scheduled_plan_code' => $plan->code,
            'scheduled_charge_period' => $billingPeriod,
            'scheduled_change_at' => $changeAt->toIso8601String(),
        ]);

        $scheduled = [
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'charge_period' => $billingPeriod,
            'change_at' => $changeAt->toIso8601String(),
        ];

        return [
            'mode' => 'downgrade',
            'subscription' => $current->refresh(),
            'invoice' => null,
            'checkout_url' => null,
            'current_subscription' => $current->refresh(),
            'proration' => null,
            'scheduled_change' => $scheduled,
        ];
    }

    private function settleZeroDueUpgrade(
        string $tenantId,
        TenantSubscription $pending,
        TenantBillingInvoice $invoice,
        TenantSubscription $previous,
    ): void {
        $now = CarbonImmutable::now('UTC');
        $invoice->forceFill([
            'status' => 'paid',
            'amount_paid_minor' => 0,
            'paid_at' => $now,
        ])->save();

        if (in_array($previous->status, ['trialing', 'active', 'past_due', 'suspended'], true)) {
            app(TenantSubscriptionLifecycle::class)->transition(
                $tenantId,
                (int) $previous->id,
                'canceled',
            );
        }

        app(TenantSubscriptionLifecycle::class)->transition(
            $tenantId,
            (int) $pending->id,
            'active',
            null,
            null,
            null,
            $pending->billing_provider,
            $pending->provider_account_id,
        );

        $this->systemAudit($tenantId, $pending, 'tenant_subscription.zero_due_plan_change_settled', [
            'invoice_id' => $invoice->id,
            'replaces_subscription_id' => $previous->id,
        ]);
    }

    private function applyScheduledChangeToSubscription(
        TenantSubscription $subscription,
        Plan $plan,
        string $period,
        CarbonImmutable $now,
    ): bool {
        $periodStart = CarbonImmutable::parse($subscription->current_period_ends_at)->utc();
        // If the period end is still in the future (clock skew / early run), wait.
        if ($periodStart->greaterThan($now)) {
            return false;
        }

        $periodEnd = $period === 'yearly' ? $periodStart->addYear() : $periodStart->addMonth();
        $amount = $plan->priceForPeriod($period);
        $provider = $subscription->billing_provider ?? 'sandbox';
        $providerAccountId = $subscription->provider_account_id ?? ('acct_'.$provider.'_'.str_replace('-', '', $subscription->tenant_id));
        $previousPlanCode = $subscription->plan_code;

        // Plan snapshots are immutable — apply by replacing the subscription row
        // (cancel current, insert new active) rather than mutating plan_code/amount.
        $this->clearScheduledChange($subscription);
        $subscription->forceFill([
            'status' => 'canceled',
            'canceled_at' => $now,
            'grace_ends_at' => null,
        ])->save();

        $providerSubscriptionId = $provider === 'kelviq'
            ? null
            : 'sub_'.$provider.'_'.Str::lower(Str::random(18));

        $replacement = TenantSubscription::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'billing_mode' => 'automated',
            'status' => 'active',
            'billing_provider' => $provider,
            'provider_account_id' => $providerAccountId,
            'provider_subscription_id' => $providerSubscriptionId,
            'currency' => $plan->currency,
            'unit_amount_minor' => $amount,
            'entitlements' => $plan->entitlements,
            'cancel_at_period_end' => false,
            'replaces_subscription_id' => $subscription->id,
            'current_period_starts_at' => $periodStart,
            'current_period_ends_at' => $periodEnd,
            'created_by_user_id' => $subscription->created_by_user_id,
        ]);

        $periodLabel = $this->formatPeriodRange($periodStart, $periodEnd);
        $lineItems = [[
            'description' => $plan->name.' ('.$period.'), '.$periodLabel,
            'quantity' => 1,
            'unit_amount_minor' => $amount,
            'amount_minor' => $amount,
        ]];

        $invoice = TenantBillingInvoice::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'tenant_subscription_id' => $replacement->id,
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
            'line_items' => $lineItems,
            'billing_details' => (array) (((array) (Tenant::query()->findOrFail($subscription->tenant_id)->settings))['billing'] ?? []),
            'issued_at' => $now,
            'due_at' => $now->addDays(7),
            'service_period_starts_at' => $periodStart,
            'service_period_ends_at' => $periodEnd,
        ]);

        // Sandbox (and app-owned renewals): settle the renewal invoice in-ledger.
        // There is no Sandbox "renewal checkout" path — we mark paid + record payment
        // here so the period advance stays consistent with webhook renewals.
        if ($amount === 0) {
            $invoice->forceFill([
                'status' => 'paid',
                'amount_paid_minor' => 0,
                'paid_at' => $now,
            ])->save();
        } else {
            TenantBillingPayment::query()->create([
                'tenant_id' => $subscription->tenant_id,
                'tenant_billing_invoice_id' => $invoice->id,
                'collection_mode' => 'automated',
                'idempotency_key' => 'scheduled-change:'.$subscription->id.':'.$periodStart->timestamp,
                'provider' => $provider,
                'provider_account_id' => $providerAccountId,
                'provider_payment_id' => 'pay_scheduled_'.Str::lower(Str::random(16)),
                'status' => 'succeeded',
                'currency' => $plan->currency,
                'amount_minor' => $amount,
                'received_at' => $now,
            ]);
            $invoice->forceFill([
                'status' => 'paid',
                'amount_paid_minor' => $amount,
                'paid_at' => $now,
            ])->save();
        }

        $this->systemAudit($subscription->tenant_id, $replacement, 'tenant_subscription.scheduled_plan_change_applied', [
            'previous_plan_code' => $previousPlanCode,
            'previous_subscription_id' => $subscription->id,
            'new_plan_code' => $plan->code,
            'charge_period' => $period,
            'invoice_id' => $invoice->id,
            'period_starts_at' => $periodStart->toIso8601String(),
            'period_ends_at' => $periodEnd->toIso8601String(),
        ]);

        app(TenantBillingOutbox::class)->enqueue(
            $subscription->tenant_id,
            'billing.subscription.scheduled_plan_change_applied:'.$replacement->id.':'.$invoice->id,
            'billing.subscription.scheduled_plan_change_applied',
            'tenant_subscription',
            $replacement->id,
            [
                'invoice_id' => $invoice->id,
                'plan_code' => $plan->code,
                'charge_period' => $period,
                'previous_subscription_id' => $subscription->id,
            ],
        );

        return true;
    }

    private function clearScheduledChange(TenantSubscription $subscription): void
    {
        if ($subscription->scheduled_plan_id === null
            && $subscription->scheduled_charge_period === null
            && $subscription->scheduled_change_at === null) {
            return;
        }

        $subscription->forceFill([
            'scheduled_plan_id' => null,
            'scheduled_charge_period' => null,
            'scheduled_change_at' => null,
        ])->save();
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

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function audit(string $tenantId, int $actorId, TenantSubscription $subscription, string $eventType, array $extraMetadata = []): void
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
                'scheduled_plan_id' => $subscription->scheduled_plan_id,
                'scheduled_charge_period' => $subscription->scheduled_charge_period,
                'scheduled_change_at' => $subscription->scheduled_change_at?->toIso8601String(),
                ...$extraMetadata,
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
                'scheduled_plan_id' => $subscription->scheduled_plan_id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function systemAudit(string $tenantId, TenantSubscription $subscription, string $eventType, array $extraMetadata = []): void
    {
        app(AuditEventWriter::class)->append([
            'actor_type' => 'system',
            'actor_id' => null,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'subject_type' => 'tenant_subscription',
            'subject_id' => (string) $subscription->id,
            'metadata' => [
                'status' => $subscription->status,
                ...$extraMetadata,
            ],
            'occurred_at' => now('UTC'),
        ]);
    }

    private function formatPeriodRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->format('M j, Y').'–'.$end->format('M j, Y');
    }
}

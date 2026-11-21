<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantBillingPayment;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TenantSubscriptionLifecycle
{
    private const TRANSITIONS = [
        'trialing' => ['active', 'past_due', 'canceled', 'expired'],
        // Pending paid plan-change; activates only after webhook settlement.
        'incomplete' => ['active', 'canceled', 'expired'],
        'active' => ['past_due', 'suspended', 'canceled', 'expired'],
        'past_due' => ['active', 'suspended', 'canceled', 'expired'],
        'suspended' => ['active', 'canceled', 'expired'],
        'canceled' => [],
        'expired' => [],
    ];

    /**
     * Apply an already-authorized subscription state change.
     *
     * A non-null actor is checked against tenant billing.manage capability.
     * Reactivation from delinquency/suspension requires a fully settled invoice
     * for this subscription. A null actor is reserved for trusted internal
     * reconciliation callers; no public webhook or HTTP endpoint is exposed.
     */
    public function transition(
        string $tenantId,
        int $subscriptionId,
        string $targetStatus,
        ?DateTimeInterface $graceEndsAt = null,
        ?int $actorUserId = null,
        ?int $settledInvoiceId = null,
        ?string $settlementProvider = null,
        ?string $settlementProviderAccountId = null,
    ): TenantSubscription {
        if (! array_key_exists($targetStatus, self::TRANSITIONS)) {
            throw new InvalidArgumentException('tenant_subscription_status_invalid');
        }

        if ($graceEndsAt !== null && $targetStatus !== 'past_due') {
            throw new InvalidArgumentException('tenant_subscription_grace_not_applicable');
        }

        if (($settlementProvider === null) !== ($settlementProviderAccountId === null)) {
            throw new InvalidArgumentException('tenant_subscription_settlement_provider_context_invalid');
        }

        if ($settledInvoiceId !== null && $targetStatus !== 'active') {
            throw new InvalidArgumentException('tenant_subscription_settlement_not_applicable');
        }

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            $actorUserId,
            fn (): TenantSubscription => DB::transaction(function () use ($tenantId, $subscriptionId, $targetStatus, $graceEndsAt, $actorUserId, $settledInvoiceId, $settlementProvider, $settlementProviderAccountId): TenantSubscription {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

                if ($actorUserId !== null) {
                    $actor = User::query()->findOrFail($actorUserId);
                    app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');
                }

                $subscription = TenantSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($subscriptionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($settlementProvider !== null
                    && ($subscription->billing_provider !== $settlementProvider
                        || $subscription->provider_account_id !== $settlementProviderAccountId)) {
                    throw new InvalidArgumentException('tenant_subscription_provider_account_mismatch');
                }

                if ($subscription->status === $targetStatus) {
                    if ($targetStatus === 'past_due') {
                        // Repeated provider delinquency notifications never extend the platform grace deadline.
                        return $subscription;
                    }

                    if ($targetStatus === 'active' && $settledInvoiceId !== null) {
                        $this->requireSettledInvoice($tenantId, $subscription, $settledInvoiceId, $settlementProvider, $settlementProviderAccountId);

                        return $subscription;
                    }

                    if ($graceEndsAt !== null) {
                        throw new InvalidArgumentException('tenant_subscription_transition_already_applied');
                    }

                    return $subscription;
                }

                if (! in_array($targetStatus, self::TRANSITIONS[$subscription->status] ?? [], true)) {
                    throw new InvalidArgumentException('tenant_subscription_transition_not_allowed');
                }

                $now = CarbonImmutable::now('UTC');
                $grace = $graceEndsAt === null
                    ? ($subscription->grace_ends_at === null ? null : CarbonImmutable::parse($subscription->grace_ends_at)->utc())
                    : CarbonImmutable::instance($graceEndsAt)->utc();

                if ($targetStatus === 'past_due' && ($grace === null || ! $grace->greaterThan($now))) {
                    throw new InvalidArgumentException('tenant_subscription_grace_period_required');
                }

                $currentPeriodEndsAt = CarbonImmutable::parse($subscription->current_period_ends_at)->utc();
                if ($targetStatus === 'expired'
                    && ($currentPeriodEndsAt->greaterThan($now)
                        || ($subscription->status === 'past_due' && $grace !== null && $grace->greaterThan($now)))) {
                    throw new InvalidArgumentException('tenant_subscription_expiry_before_period_end');
                }

                if ($targetStatus === 'active' && in_array($subscription->status, ['past_due', 'suspended'], true)) {
                    if ($settledInvoiceId !== null) {
                        $this->requireSettledInvoice($tenantId, $subscription, $settledInvoiceId, $settlementProvider, $settlementProviderAccountId);
                    } elseif ($subscription->status === 'past_due' || $settlementProvider === null) {
                        // past_due always needs a settled invoice; suspended may resume via provider attestation.
                        throw new InvalidArgumentException('billing_webhook_settled_invoice_required');
                    }
                }

                if ($targetStatus !== 'past_due') {
                    $grace = null;
                }

                $previousStatus = $subscription->status;
                $subscription->forceFill([
                    'status' => $targetStatus,
                    'grace_ends_at' => $grace,
                    'canceled_at' => $targetStatus === 'canceled' ? $now : $subscription->canceled_at,
                ])->save();

                app(AuditEventWriter::class)->append([
                    'actor_type' => $actorUserId === null ? 'system' : 'user',
                    'actor_id' => $actorUserId === null ? null : (string) $actorUserId,
                    'tenant_id' => $tenantId,
                    'event_type' => 'tenant_subscription.status_changed',
                    'subject_type' => 'tenant_subscription',
                    'subject_id' => (string) $subscription->id,
                    'metadata' => [
                        'previous_status' => $previousStatus,
                        'new_status' => $targetStatus,
                        'grace_ends_at' => $grace?->utc()->toIso8601String(),
                        'settled_invoice_id' => $settledInvoiceId,
                    ],
                    'occurred_at' => $now,
                ]);

                app(TenantBillingOutbox::class)->enqueue(
                    $tenantId,
                    'billing.subscription.status_changed:'.$subscription->id.':'.Str::uuid(),
                    'billing.subscription.status_changed',
                    'tenant_subscription',
                    $subscription->id,
                    [
                        'previous_status' => $previousStatus,
                        'new_status' => $targetStatus,
                        'grace_ends_at' => $grace?->utc()->toIso8601String(),
                    ],
                );

                return $subscription->refresh();
            }, 3),
        );
    }

    public function renewFromSettledInvoice(
        string $tenantId,
        int $subscriptionId,
        int $invoiceId,
        DateTimeInterface $periodStartsAt,
        DateTimeInterface $periodEndsAt,
        string $provider,
        string $providerAccountId,
    ): TenantSubscription {
        $periodStart = CarbonImmutable::instance($periodStartsAt)->utc();
        $periodEnd = CarbonImmutable::instance($periodEndsAt)->utc();
        $now = CarbonImmutable::now('UTC');

        if (! $periodEnd->greaterThan($periodStart)
            || ! $periodEnd->greaterThan($now)
            || $periodEnd->diffInDays($periodStart) > 366
            || ! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)
            || trim($providerAccountId) === '' || strlen($providerAccountId) > 160) {
            throw new InvalidArgumentException('tenant_subscription_renewal_period_invalid');
        }

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            null,
            fn (): TenantSubscription => DB::transaction(function () use ($tenantId, $subscriptionId, $invoiceId, $periodStart, $periodEnd, $provider, $providerAccountId): TenantSubscription {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

                $subscription = TenantSubscription::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($subscriptionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($subscription->billing_provider !== $provider || $subscription->provider_account_id !== $providerAccountId) {
                    throw new InvalidArgumentException('tenant_subscription_provider_account_mismatch');
                }

                if (! in_array($subscription->status, ['trialing', 'active', 'past_due', 'suspended'], true)) {
                    throw new InvalidArgumentException('tenant_subscription_renewal_status_invalid');
                }

                $this->requireSettledInvoice($tenantId, $subscription, $invoiceId, $provider, $providerAccountId);

                $currentPeriodStartsAt = CarbonImmutable::parse($subscription->current_period_starts_at)->utc();
                $currentPeriodEndsAt = CarbonImmutable::parse($subscription->current_period_ends_at)->utc();
                if ($periodStart->startOfSecond()->equalTo($currentPeriodStartsAt->startOfSecond())
                    && $periodEnd->startOfSecond()->equalTo($currentPeriodEndsAt->startOfSecond())) {
                    return $subscription;
                }

                if (! $periodStart->startOfSecond()->equalTo($currentPeriodEndsAt->startOfSecond())) {
                    throw new InvalidArgumentException('tenant_subscription_renewal_period_gap');
                }

                $previousStatus = $subscription->status;
                $subscription->forceFill([
                    'status' => 'active',
                    'current_period_starts_at' => $periodStart,
                    'current_period_ends_at' => $periodEnd,
                    'grace_ends_at' => null,
                ])->save();

                app(AuditEventWriter::class)->append([
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'tenant_id' => $tenantId,
                    'event_type' => 'tenant_subscription.renewed',
                    'subject_type' => 'tenant_subscription',
                    'subject_id' => (string) $subscription->id,
                    'metadata' => [
                        'previous_status' => $previousStatus,
                        'new_status' => 'active',
                        'invoice_id' => $invoiceId,
                        'period_starts_at' => $periodStart->toIso8601String(),
                        'period_ends_at' => $periodEnd->toIso8601String(),
                    ],
                    'occurred_at' => now('UTC'),
                ]);

                app(TenantBillingOutbox::class)->enqueue(
                    $tenantId,
                    'billing.subscription.renewed:'.$subscription->id.':'.$invoiceId,
                    'billing.subscription.renewed',
                    'tenant_subscription',
                    $subscription->id,
                    [
                        'invoice_id' => $invoiceId,
                        'period_starts_at' => $periodStart->toIso8601String(),
                        'period_ends_at' => $periodEnd->toIso8601String(),
                    ],
                );

                return $subscription->refresh();
            }, 3),
        );
    }

    private function requireSettledInvoice(string $tenantId, TenantSubscription $subscription, ?int $invoiceId, ?string $provider = null, ?string $providerAccountId = null): void
    {
        if ($invoiceId === null) {
            throw new InvalidArgumentException('tenant_subscription_settled_invoice_required');
        }

        $invoice = TenantBillingInvoice::query()
            ->whereKey($invoiceId)
            ->where('tenant_id', $tenantId)
            ->where('tenant_subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->lockForUpdate()
            ->first();

        if ($invoice === null
            || ($provider !== null && ($invoice->provider !== $provider || $invoice->provider_account_id !== $providerAccountId))
            || $invoice->currency !== $subscription->currency
            || $invoice->amount_paid_minor < $invoice->amount_due_minor) {
            throw new InvalidArgumentException('tenant_subscription_settled_invoice_invalid');
        }

        $payments = TenantBillingPayment::query()
            ->where('tenant_id', $tenantId)
            ->where('tenant_billing_invoice_id', $invoice->id)
            ->where('status', 'succeeded');

        if ($provider !== null && $providerAccountId !== null) {
            $payments->where('provider', $provider)->where('provider_account_id', $providerAccountId);
        }

        $confirmedAmount = $payments->sum('amount_minor');

        if ((int) $confirmedAmount < $invoice->amount_due_minor) {
            throw new InvalidArgumentException('tenant_subscription_settled_invoice_invalid');
        }
    }
}

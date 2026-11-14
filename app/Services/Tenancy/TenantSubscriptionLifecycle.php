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
use InvalidArgumentException;

class TenantSubscriptionLifecycle
{
    private const TRANSITIONS = [
        'trialing' => ['active', 'past_due', 'canceled', 'expired'],
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
    ): TenantSubscription {
        if (! array_key_exists($targetStatus, self::TRANSITIONS)) {
            throw new InvalidArgumentException('tenant_subscription_status_invalid');
        }

        if ($graceEndsAt !== null && $targetStatus !== 'past_due') {
            throw new InvalidArgumentException('tenant_subscription_grace_not_applicable');
        }

        if ($settledInvoiceId !== null && $targetStatus !== 'active') {
            throw new InvalidArgumentException('tenant_subscription_settlement_not_applicable');
        }

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            $actorUserId,
            fn (): TenantSubscription => DB::transaction(function () use ($tenantId, $subscriptionId, $targetStatus, $graceEndsAt, $actorUserId, $settledInvoiceId): TenantSubscription {
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

                if ($subscription->status === $targetStatus) {
                    if ($graceEndsAt !== null || $settledInvoiceId !== null) {
                        throw new InvalidArgumentException('tenant_subscription_transition_already_applied');
                    }

                    return $subscription;
                }

                if (! in_array($targetStatus, self::TRANSITIONS[$subscription->status] ?? [], true)) {
                    throw new InvalidArgumentException('tenant_subscription_transition_not_allowed');
                }

                $now = CarbonImmutable::now('UTC');
                $grace = $graceEndsAt === null
                    ? $subscription->grace_ends_at
                    : CarbonImmutable::instance($graceEndsAt)->utc();

                if ($targetStatus === 'past_due' && ($grace === null || ! $grace->greaterThan($now))) {
                    throw new InvalidArgumentException('tenant_subscription_grace_period_required');
                }

                if ($targetStatus === 'expired'
                    && ($subscription->current_period_ends_at->greaterThan($now)
                        || ($subscription->status === 'past_due' && $grace !== null && $grace->greaterThan($now)))) {
                    throw new InvalidArgumentException('tenant_subscription_expiry_before_period_end');
                }

                if ($targetStatus === 'active' && in_array($subscription->status, ['past_due', 'suspended'], true)) {
                    $this->requireSettledInvoice($tenantId, $subscription, $settledInvoiceId);
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
                    'billing.subscription.status_changed:'.$subscription->id.':'.$subscription->updated_at?->getTimestamp(),
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

    private function requireSettledInvoice(string $tenantId, TenantSubscription $subscription, ?int $invoiceId): void
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
            || $invoice->currency !== $subscription->currency
            || $invoice->amount_paid_minor < $invoice->amount_due_minor) {
            throw new InvalidArgumentException('tenant_subscription_settled_invoice_invalid');
        }

        $confirmedAmount = TenantBillingPayment::query()
            ->where('tenant_id', $tenantId)
            ->where('tenant_billing_invoice_id', $invoice->id)
            ->where('status', 'succeeded')
            ->sum('amount_minor');

        if ((int) $confirmedAmount < $invoice->amount_due_minor) {
            throw new InvalidArgumentException('tenant_subscription_settled_invoice_invalid');
        }
    }
}

<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
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
     * A null actor is reserved for trusted internal reconciliation callers;
     * this service is not exposed through a public webhook or HTTP endpoint.
     */
    public function transition(
        string $tenantId,
        int $subscriptionId,
        string $targetStatus,
        ?DateTimeInterface $graceEndsAt = null,
        ?int $actorUserId = null,
    ): TenantSubscription {
        if (! array_key_exists($targetStatus, self::TRANSITIONS)) {
            throw new InvalidArgumentException('tenant_subscription_status_invalid');
        }

        if ($graceEndsAt !== null && $targetStatus !== 'past_due') {
            throw new InvalidArgumentException('tenant_subscription_grace_not_applicable');
        }

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            $actorUserId,
            fn (): TenantSubscription => DB::transaction(function () use ($tenantId, $subscriptionId, $targetStatus, $graceEndsAt, $actorUserId): TenantSubscription {
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
                    ? $subscription->grace_ends_at
                    : CarbonImmutable::instance($graceEndsAt)->utc();

                if ($targetStatus === 'past_due' && ($grace === null || ! $grace->greaterThan($now))) {
                    throw new InvalidArgumentException('tenant_subscription_grace_period_required');
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
                    ],
                    'occurred_at' => $now,
                ]);

                return $subscription->refresh();
            }, 3),
        );
    }
}

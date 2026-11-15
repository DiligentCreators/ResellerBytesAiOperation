<?php

namespace App\\Services\\Tenancy;

use App\\Models\\Tenant;
use App\\Models\\TenantBillingProviderAccount;
use App\\Models\\TenantBillingWebhookEvent;
use App\\Models\\User;
use App\\Services\\Audit\\AuditEventWriter;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingWebhookRecoveryService
{
    private const MAX_MANUAL_REPLAYS = 3;

    public function requeueDeadLetter(string $tenantId, int $eventId, int $actorUserId, string $reasonCode): TenantBillingWebhookEvent
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{2,79}$/', $reasonCode)) {
            throw new InvalidArgumentException('billing_webhook_replay_reason_invalid');
        }

        return app(TenantDatabaseContext::class)->run($tenantId, $actorUserId, fn (): TenantBillingWebhookEvent => DB::transaction(function () use ($tenantId, $eventId, $actorUserId, $reasonCode): TenantBillingWebhookEvent {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actorUserId);
            app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');

            $event = TenantBillingWebhookEvent::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($eventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->status !== 'dead_lettered') {
                throw new RuntimeException('billing_webhook_event_not_dead_lettered');
            }

            if ($event->manual_replay_count >= self::MAX_MANUAL_REPLAYS) {
                throw new RuntimeException('billing_webhook_manual_replay_limit_reached');
            }

            $mapping = TenantBillingProviderAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', $event->provider)
                ->where('provider_account_id', $event->provider_account_id)
                ->where('status', 'active')
                ->whereNotNull('verified_at')
                ->lockForUpdate()
                ->first();

            if ($mapping === null) {
                throw new RuntimeException('billing_webhook_provider_account_inactive');
            }

            $event->forceFill([
                'status' => 'pending',
                'attempts' => 0,
                'manual_replay_count' => $event->manual_replay_count + 1,
                'available_at' => now('UTC'),
                'locked_at' => null,
                'dead_lettered_at' => null,
                'last_error_code' => null,
            ])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actorUserId,
                'tenant_id' => $tenantId,
                'event_type' => 'tenant_billing.webhook_dead_letter_requeued',
                'subject_type' => 'tenant_billing_webhook_event',
                'subject_id' => (string) $event->id,
                'metadata' => [
                    'provider' => $event->provider,
                    'provider_account_id' => $event->provider_account_id,
                    'event_type' => $event->event_type,
                    'manual_replay_count' => $event->manual_replay_count,
                    'reason_code' => $reasonCode,
                ],
                'occurred_at' => now('UTC'),
            ]);

            return $event->refresh();
        }, 3));
    }
}

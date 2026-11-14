<?php

namespace App\\Services\\Tenancy;

use App\\Models\\Tenant;
use App\\Models\\TenantBillingOutboxMessage;
use App\\Models\\User;
use App\\Services\\Audit\\AuditEventWriter;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingOutboxRecoveryService
{
    private const MAX_MANUAL_REPLAYS = 3;

    public function requeueDeadLetter(string $tenantId, int $messageId, int $actorUserId, string $reasonCode): TenantBillingOutboxMessage
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{2,79}$/', $reasonCode)) {
            throw new InvalidArgumentException('billing_outbox_replay_reason_invalid');
        }

        return app(TenantDatabaseContext::class)->run($tenantId, $actorUserId, fn (): TenantBillingOutboxMessage => DB::transaction(function () use ($tenantId, $messageId, $actorUserId, $reasonCode): TenantBillingOutboxMessage {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actorUserId);
            app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');

            $message = TenantBillingOutboxMessage::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($messageId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($message->status !== 'dead_lettered') {
                throw new RuntimeException('billing_outbox_message_not_dead_lettered');
            }

            if ($message->manual_replay_count >= self::MAX_MANUAL_REPLAYS) {
                throw new RuntimeException('billing_outbox_manual_replay_limit_reached');
            }

            $message->forceFill([
                'status' => 'pending',
                'attempts' => 0,
                'manual_replay_count' => $message->manual_replay_count + 1,
                'available_at' => now('UTC'),
                'locked_at' => null,
                'dead_lettered_at' => null,
                'last_error_code' => null,
            ])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actorUserId,
                'tenant_id' => $tenantId,
                'event_type' => 'tenant_billing.outbox_dead_letter_requeued',
                'subject_type' => 'tenant_billing_outbox_message',
                'subject_id' => (string) $message->id,
                'metadata' => [
                    'event_type' => $message->event_type,
                    'aggregate_type' => $message->aggregate_type,
                    'aggregate_id' => $message->aggregate_id,
                    'manual_replay_count' => $message->manual_replay_count,
                    'reason_code' => $reasonCode,
                ],
                'occurred_at' => now('UTC'),
            ]);

            return $message->refresh();
        }, 3));
    }
}

<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantNotificationService
{
    /**
     * @param array<string, scalar|null> $payload
     */
    public function publish(string $tenantId, int $userId, string $eventType, array $payload, string $notificationKey): TenantNotification
    {
        $scalarPayload = count(array_filter($payload, static fn (mixed $value): bool => is_scalar($value) || $value === null)) === count($payload);
        if (! $scalarPayload
            || preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $eventType) !== 1
            || preg_match('/^[A-Za-z0-9:_.-]{1,120}$/', $notificationKey) !== 1
            || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 8192) {
            throw new InvalidArgumentException('tenant_notification_payload_invalid');
        }

        return DB::transaction(function () use ($tenantId, $userId, $eventType, $payload, $notificationKey): TenantNotification {
            $tenant = Tenant::query()->whereKey($tenantId)->where('status', Tenant::STATUS_ACTIVE)->lockForUpdate()->first();
            if ($tenant === null) {
                throw (new ModelNotFoundException())->setModel(Tenant::class, [$tenantId]);
            }

            $membership = TenantMembership::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('status', TenantMembership::STATUS_ACTIVE)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $membership->isActive()) {
                throw (new ModelNotFoundException())->setModel(TenantMembership::class);
            }

            $existing = TenantNotification::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('event_type', $eventType)->where('notification_key', $notificationKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            return TenantNotification::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'event_type' => $eventType,
                'notification_key' => $notificationKey,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        }, 3);
    }
}

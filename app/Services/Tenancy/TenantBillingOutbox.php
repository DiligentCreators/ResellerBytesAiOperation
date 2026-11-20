<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantBillingOutboxMessage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class TenantBillingOutbox
{
    /**
     * Enqueue an integration event in the caller's business transaction.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $tenantId,
        string $idempotencyKey,
        string $eventType,
        string $aggregateType,
        string|int $aggregateId,
        array $payload,
    ): TenantBillingOutboxMessage {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('billing_outbox_requires_business_transaction');
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $idempotencyKey)
            || ! preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $eventType)
            || ! preg_match('/^[a-z][a-z0-9_.-]{1,59}$/', $aggregateType)
            || (string) $aggregateId === ''
            || strlen((string) $aggregateId) > 120) {
            throw new InvalidArgumentException('billing_outbox_input_invalid');
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 65536) {
            throw new InvalidArgumentException('billing_outbox_payload_too_large');
        }

        return app(TenantDatabaseContext::class)->run($tenantId, null, function () use ($tenantId, $idempotencyKey, $eventType, $aggregateType, $aggregateId, $payload): TenantBillingOutboxMessage {
            // Suspended tenants still need billing lifecycle and reconciliation events.
            Tenant::query()->whereKey($tenantId)->firstOrFail();

            $existing = TenantBillingOutboxMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->event_type !== $eventType
                    || $existing->aggregate_type !== $aggregateType
                    || $existing->aggregate_id !== (string) $aggregateId
                    || $this->canonicalJson($existing->payload) !== $this->canonicalJson($payload)) {
                    throw new RuntimeException('billing_outbox_idempotency_conflict');
                }

                return $existing;
            }

            return TenantBillingOutboxMessage::query()->create([
                'tenant_id' => $tenantId,
                'idempotency_key' => $idempotencyKey,
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => (string) $aggregateId,
                'payload' => $payload,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now('UTC'),
            ]);
        });
    }

    /** @param array<string, mixed>|null $value */
    private function canonicalJson(?array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item);
            }

            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return json_encode($normalize($value ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

<?php

namespace App\Events;

class TenantBillingOutboxPublished
{
    /**
     * Delivery is at-least-once. Listeners must deduplicate by outboxId.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $outboxId,
        public readonly string $eventType,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly array $payload,
    ) {}
}

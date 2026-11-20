<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class VerifiedBillingWebhook
{
    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function __construct(
        public string $providerAccountId,
        public string $providerEventId,
        public string $eventType,
        public array $normalizedPayload,
        public CarbonImmutable $signatureTimestamp,
        public CarbonImmutable $occurredAt,
    ) {}
}

<?php

namespace App\Services\Audit;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;

final class AuditEventCanonicalizer
{
    public const VERSION = 1;

    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const DOMAIN = "resellerbytes.audit-event.v1\n";

    /**
     * @param array<string, mixed> $event
     *
     * @throws JsonException
     */
    public function canonicalPayload(array $event): string
    {
        $metadata = $event['metadata'] ?? null;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        }

        $payload = [
            'chain_version' => self::VERSION,
            'chain_sequence' => (int) $event['chain_sequence'],
            'previous_hash' => (string) $event['previous_hash'],
            'actor_type' => $event['actor_type'] ?? null,
            'actor_id' => $event['actor_id'] ?? null,
            'event_type' => (string) $event['event_type'],
            'subject_type' => $event['subject_type'] ?? null,
            'subject_id' => $event['subject_id'] ?? null,
            'metadata' => $this->sortMetadata($metadata),
            'ip_address' => $event['ip_address'] ?? null,
            'occurred_at' => $this->normalizeTimestamp($event['occurred_at']),
        ];

        return self::DOMAIN.json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * @param array<string, mixed> $event
     *
     * @throws JsonException
     */
    public function hash(array $event): string
    {
        return hash('sha256', $this->canonicalPayload($event));
    }

    private function sortMetadata(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortMetadata($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortMetadata($item);
        }

        return $value;
    }

    private function normalizeTimestamp(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            $date = CarbonImmutable::instance($value);
        } elseif (is_string($value) && $value !== '') {
            $date = CarbonImmutable::parse($value);
        } else {
            throw new InvalidArgumentException('Audit event timestamp must be a date-time value.');
        }

        return $date->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}

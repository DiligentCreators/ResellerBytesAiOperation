<?php

namespace App\Services\Audit;

use App\Contracts\AuditCheckpointObjectStore;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use SodiumException;

final class AuditCheckpointPublisher
{
    private const RETENTION_DAYS = 365;

    public function __construct(
        private readonly AuditCheckpointObjectStore $store,
        private readonly AuditAnchorService $anchors,
    ) {}

    /**
     * @return array{object_key: string, object_version_id: string, checkpoint_sha256: string, chain_sequence: int, retain_until: string}
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function publish(string $checkpointBytes): array
    {
        $verified = $this->anchors->verifyCheckpoint($checkpointBytes);
        $envelope = json_decode($checkpointBytes, true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($envelope) ? ($envelope['payload'] ?? null) : null;
        if (! is_array($payload) || ! is_string($payload['environment'] ?? null)) {
            throw new RuntimeException('audit_anchor_publish_invalid_checkpoint');
        }

        $environment = $payload['environment'];
        if (preg_match('/\A[a-zA-Z0-9._-]+\z/', $environment) !== 1) {
            throw new RuntimeException('audit_anchor_publish_invalid_environment');
        }

        $digest = $verified['checkpoint_sha256'];
        $sequence = $verified['anchor_sequence'];
        $objectKey = sprintf('audit-anchors/%s/sequence-%020d/%s.json', $environment, $sequence, $digest);
        $retainUntil = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+'.self::RETENTION_DAYS.' days');
        // A duplicate or ambiguous create is an error. Never retry with overwrite semantics.
        $created = $this->store->createImmutableIfAbsent($objectKey, $checkpointBytes, $retainUntil);
        if (! is_string($created->versionId) || $created->versionId === '') {
            throw new RuntimeException('audit_anchor_publish_version_missing');
        }

        $readBack = $this->store->readImmutableVersion($objectKey, $created->versionId);
        if (! is_string($readBack->versionId) || ! hash_equals($created->versionId, $readBack->versionId)) {
            throw new RuntimeException('audit_anchor_publish_version_mismatch');
        }
        if (! hash_equals($digest, hash('sha256', $readBack->contents))) {
            throw new RuntimeException('audit_anchor_publish_checksum_mismatch');
        }
        if (strtoupper($readBack->retentionMode) !== 'COMPLIANCE') {
            throw new RuntimeException('audit_anchor_publish_retention_mode_mismatch');
        }
        if ($readBack->retainUntil->getTimestamp() < $retainUntil->getTimestamp()) {
            throw new RuntimeException('audit_anchor_publish_retention_too_short');
        }

        return [
            'object_key' => $objectKey,
            'object_version_id' => $created->versionId,
            'checkpoint_sha256' => $digest,
            'chain_sequence' => $sequence,
            'retain_until' => $readBack->retainUntil->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

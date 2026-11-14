<?php

namespace App\Services\Audit;

use App\Contracts\AuditCheckpointObjectStore;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use SodiumException;
use Throwable;

final class AuditCheckpointPublisher
{
    private const RETENTION_DAYS = 365;

    public function __construct(
        private readonly AuditCheckpointObjectStore $store,
        private readonly AuditAnchorService $anchors,
    ) {}

    /**
     * @return array{object_key: string, object_version_id: string, checkpoint_sha256: string, chain_sequence: int, retain_until: string, reconciled_after_create_error: bool}
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function publish(string $checkpointBytes, ?string $expectedPreviousCheckpointSha256 = null): array
    {
        $envelope = json_decode($checkpointBytes, true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($envelope) ? ($envelope['payload'] ?? null) : null;
        if (! is_array($payload) || ! is_string($payload['environment'] ?? null)) {
            throw new RuntimeException('audit_anchor_publish_invalid_checkpoint');
        }

        $payloadPrevious = $payload['previous_checkpoint_sha256'] ?? null;
        if ($payloadPrevious !== null && $expectedPreviousCheckpointSha256 === null) {
            throw new RuntimeException('audit_anchor_publish_expected_previous_required');
        }
        $verified = $this->anchors->verifyCheckpoint($checkpointBytes, $expectedPreviousCheckpointSha256);
        $environment = $payload['environment'];
        $createdAtText = $payload['created_at'] ?? null;
        if (! is_string($createdAtText)) {
            throw new RuntimeException('audit_anchor_publish_invalid_timestamp');
        }

        $createdAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s.u\\Z',
            $createdAtText,
            new DateTimeZone('UTC'),
        );
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($createdAt === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $createdAt->format('Y-m-d\\TH:i:s.u\\Z') !== $createdAtText) {
            throw new RuntimeException('audit_anchor_publish_invalid_timestamp');
        }
        if (preg_match('/\A[a-zA-Z0-9._-]+\z/', $environment) !== 1) {
            throw new RuntimeException('audit_anchor_publish_invalid_environment');
        }

        $digest = $verified['checkpoint_sha256'];
        $sequence = $verified['anchor_sequence'];
        $objectKey = sprintf('audit-anchors/%s/sequence-%020d/%s.json', $environment, $sequence, $digest);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($createdAt > $now->modify('+5 minutes')) {
            throw new RuntimeException('audit_anchor_publish_timestamp_in_future');
        }

        $retainUntil = $createdAt->modify('+'.self::RETENTION_DAYS.' days');
        if ($retainUntil <= $now) {
            throw new RuntimeException('audit_anchor_publish_retention_period_expired');
        }
        // Never retry a create. After an error, resolve only the deterministic key and
        // accept it only after exact-version content and retention read-back.
        $reconciledAfterCreateError = false;
        try {
            $created = $this->store->createImmutableIfAbsent($objectKey, $checkpointBytes, $retainUntil);
        } catch (Throwable $createException) {
            try {
                $created = $this->store->findImmutableObject($objectKey);
            } catch (Throwable $lookupException) {
                throw new RuntimeException('audit_anchor_publish_outcome_ambiguous', 0, $lookupException);
            }

            if ($created === null) {
                throw $createException;
            }

            $reconciledAfterCreateError = true;
        }

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
            'reconciled_after_create_error' => $reconciledAfterCreateError,
        ];
    }
}

<?php

use App\Contracts\AuditCheckpointObjectStore;
use App\Data\AuditCheckpointObjectVersion;
use App\Services\Audit\AuditAnchorService;
use App\Services\Audit\AuditCheckpointPublisher;
use App\Services\Audit\AuditEventWriter;
use DateTimeImmutable;
use RuntimeException;

final class FakeAuditCheckpointObjectStore implements AuditCheckpointObjectStore
{
    public ?AuditCheckpointObjectVersion $createResult = null;

    public ?AuditCheckpointObjectVersion $readResult = null;

    public ?RuntimeException $createFailure = null;

    public ?RuntimeException $readFailure = null;

    public array $createCalls = [];

    public array $readCalls = [];

    public function createImmutableIfAbsent(string $objectKey, string $contents, DateTimeImmutable $retainUntil): AuditCheckpointObjectVersion
    {
        $this->createCalls[] = [$objectKey, $contents, $retainUntil];

        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        return $this->createResult ?? new AuditCheckpointObjectVersion($contents, 'version-1', 'COMPLIANCE', $retainUntil);
    }

    public function readImmutableVersion(string $objectKey, string $versionId): AuditCheckpointObjectVersion
    {
        $this->readCalls[] = [$objectKey, $versionId];

        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }

        return $this->readResult ?? new AuditCheckpointObjectVersion(
            $this->createCalls[0][1],
            $versionId,
            'COMPLIANCE',
            $this->createCalls[0][2],
        );
    }
}

beforeEach(function () {
    $keyPair = sodium_crypto_sign_keypair();
    config([
        'audit.anchor.private_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
        'audit.anchor.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
    ]);
});

function createPublishableAuditCheckpoint(): string
{
    app(AuditEventWriter::class)->append([
        'event_type' => 'test.anchor.publish',
        'subject_type' => 'test',
        'subject_id' => 'publisher-fixture',
        'metadata' => ['source' => 'automated-test'],
        'occurred_at' => now(),
    ]);

    return app(AuditAnchorService::class)->createCheckpoint();
}

it('publishes only after exact-version checksum and compliance retention read-back', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $publisher = new AuditCheckpointPublisher($store, app(AuditAnchorService::class));

    $result = $publisher->publish($checkpoint);

    expect($result['checkpoint_sha256'])->toBe(hash('sha256', $checkpoint))
        ->and($result['chain_sequence'])->toBe(1)
        ->and($result['object_version_id'])->toBe('version-1')
        ->and($result['object_key'])->toContain('audit-anchors/testing/sequence-')
        ->and($store->createCalls)->toHaveCount(1)
        ->and($store->readCalls)->toHaveCount(1);
});

it('fails closed when conditional object creation reports a duplicate key', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->createFailure = new RuntimeException('audit_anchor_object_already_exists');

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_object_already_exists');

    expect($store->readCalls)->toBeEmpty();
});

it('does not report success when the create response has no version identifier', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->createResult = new AuditCheckpointObjectVersion($checkpoint, null, 'COMPLIANCE', new DateTimeImmutable('+366 days'));

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_version_missing');

    expect($store->readCalls)->toBeEmpty();
});

it('rejects a read-back checksum mismatch', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->readResult = new AuditCheckpointObjectVersion('tampered', 'version-1', 'COMPLIANCE', new DateTimeImmutable('+366 days'));

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_checksum_mismatch');
});

it('rejects a read-back object version mismatch', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->readResult = new AuditCheckpointObjectVersion($checkpoint, 'different-version', 'COMPLIANCE', new DateTimeImmutable('+366 days'));

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_version_mismatch');
});

it('rejects governance or missing retention mode', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->readResult = new AuditCheckpointObjectVersion($checkpoint, 'version-1', 'GOVERNANCE', new DateTimeImmutable('+366 days'));

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_retention_mode_mismatch');
});

it('rejects retention shorter than the required 365 days', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->readResult = new AuditCheckpointObjectVersion($checkpoint, 'version-1', 'COMPLIANCE', new DateTimeImmutable('+300 days'));

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_retention_too_short');
});

it('does not retry or claim success after an ambiguous create response', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->createFailure = new RuntimeException('audit_anchor_upload_outcome_ambiguous');

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_upload_outcome_ambiguous');

    expect($store->createCalls)->toHaveCount(1)
        ->and($store->readCalls)->toBeEmpty();
});

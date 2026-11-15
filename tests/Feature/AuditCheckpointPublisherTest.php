<?php

use App\Contracts\AuditCheckpointObjectStore;
use App\Data\AuditCheckpointObjectVersion;
use App\Services\Audit\AuditAnchorService;
use App\Services\Audit\AuditCheckpointPublisher;
use App\Services\Audit\AuditEventWriter;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class FakeAuditCheckpointObjectStore implements AuditCheckpointObjectStore
{
    public ?AuditCheckpointObjectVersion $createResult = null;

    public ?AuditCheckpointObjectVersion $readResult = null;

    public ?AuditCheckpointObjectVersion $findResult = null;

    public ?RuntimeException $createFailure = null;

    public ?RuntimeException $readFailure = null;

    public ?RuntimeException $findFailure = null;

    public array $createCalls = [];

    public array $readCalls = [];

    public array $findCalls = [];

    public function createImmutableIfAbsent(string $objectKey, string $contents, DateTimeImmutable $retainUntil): AuditCheckpointObjectVersion
    {
        $this->createCalls[] = [$objectKey, $contents, $retainUntil];

        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        return $this->createResult ?? new AuditCheckpointObjectVersion($contents, 'version-1', 'COMPLIANCE', $retainUntil);
    }

    public function findImmutableObject(string $objectKey): ?AuditCheckpointObjectVersion
    {
        $this->findCalls[] = [$objectKey];

        if ($this->findFailure !== null) {
            throw $this->findFailure;
        }

        return $this->findResult;
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

it('rejects an unsigned or tampered checkpoint before calling the object store', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $envelope = json_decode($checkpoint, true, 512, JSON_THROW_ON_ERROR);
    $envelope['payload']['chain_sequence'] = 999;
    $tampered = json_encode($envelope, JSON_THROW_ON_ERROR);
    $store = new FakeAuditCheckpointObjectStore;

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($tampered))
        ->toThrow(RuntimeException::class, 'audit_anchor_signature_invalid');

    expect($store->createCalls)->toBeEmpty();
});

it('reconciles an ambiguous create only after deterministic lookup and exact read-back', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $retainUntil = new DateTimeImmutable('+366 days');
    $store = new FakeAuditCheckpointObjectStore;
    $store->createFailure = new RuntimeException('audit_anchor_upload_outcome_ambiguous');
    $store->findResult = new AuditCheckpointObjectVersion($checkpoint, 'recovered-version', 'COMPLIANCE', $retainUntil);
    $store->readResult = $store->findResult;
    $publisher = new AuditCheckpointPublisher($store, app(AuditAnchorService::class));

    $result = $publisher->publish($checkpoint);

    expect($result['object_version_id'])->toBe('recovered-version')
        ->and($result['reconciled_after_create_error'])->toBeTrue()
        ->and($store->createCalls)->toHaveCount(1)
        ->and($store->findCalls)->toHaveCount(1)
        ->and($store->readCalls)->toHaveCount(1);
});

it('does not claim success when an ambiguous create cannot be resolved', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->createFailure = new RuntimeException('audit_anchor_upload_outcome_ambiguous');

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_upload_outcome_ambiguous');

    expect($store->createCalls)->toHaveCount(1)
        ->and($store->findCalls)->toHaveCount(1)
        ->and($store->readCalls)->toBeEmpty();
});

it('fails closed when lookup cannot resolve an ambiguous create outcome', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->createFailure = new RuntimeException('audit_anchor_upload_outcome_ambiguous');
    $store->findFailure = new RuntimeException('audit_anchor_lookup_unavailable');

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_outcome_ambiguous');

    expect($store->createCalls)->toHaveCount(1)
        ->and($store->findCalls)->toHaveCount(1)
        ->and($store->readCalls)->toBeEmpty();
});

it('does not report success when exact-version read-back fails', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $store->readFailure = new RuntimeException('audit_anchor_readback_unavailable');

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_readback_unavailable');

    expect($store->createCalls)->toHaveCount(1)
        ->and($store->readCalls)->toHaveCount(1);
});

it('publishes only after exact-version checksum and compliance retention read-back', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $store = new FakeAuditCheckpointObjectStore;
    $publisher = new AuditCheckpointPublisher($store, app(AuditAnchorService::class));

    $result = $publisher->publish($checkpoint);

    expect($result['checkpoint_sha256'])->toBe(hash('sha256', $checkpoint))
        ->and($result['chain_sequence'])->toBe(1)
        ->and($result['object_version_id'])->toBe('version-1')
        ->and($result['environment'])->toBe('testing')
        ->and($result['retention_mode'])->toBe('COMPLIANCE')
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

it('requires the exact prior checkpoint digest when publishing a chained checkpoint', function () {
    $checkpoint = app(AuditAnchorService::class)->createCheckpoint(str_repeat('a', 64));
    $store = new FakeAuditCheckpointObjectStore;
    $publisher = new AuditCheckpointPublisher($store, app(AuditAnchorService::class));

    expect(fn () => $publisher->publish($checkpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_expected_previous_required');

    expect($store->createCalls)->toBeEmpty()
        ->and($publisher->publish($checkpoint, str_repeat('a', 64))['chain_sequence'])->toBe(0)
        ->and($store->createCalls)->toHaveCount(1);
});

it('derives Object Lock expiry from the signed checkpoint timestamp for idempotent retries', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $payload = json_decode($checkpoint, true, 512, JSON_THROW_ON_ERROR)['payload'];
    $expectedRetention = (new DateTimeImmutable($payload['created_at']))->modify('+365 days');
    $store = new FakeAuditCheckpointObjectStore;

    (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($checkpoint);

    expect($store->createCalls[0][2]->getTimestamp())->toBe($expectedRetention->getTimestamp());
});

it('registers the publish command and fails closed before storage access without a dedicated bucket', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $path = tempnam(sys_get_temp_dir(), 'rb-audit-publish-command-');
    file_put_contents($path, $checkpoint);
    config(['audit.anchor.storage.bucket' => null]);

    try {
        expect(Artisan::call('audit:anchor:publish', ['checkpoint' => $path]))->toBe(1)
            ->and(Artisan::output())->toContain('audit_anchor_publish_outcome_ambiguous');
    } finally {
        @unlink($path);
    }
});

it('rejects a correctly signed checkpoint dated too far in the future', function () {
    $checkpoint = createPublishableAuditCheckpoint();
    $envelope = json_decode($checkpoint, true, 512, JSON_THROW_ON_ERROR);
    $envelope['payload']['created_at'] = (new DateTimeImmutable('+1 day'))->format('Y-m-d\\TH:i:s.u\\Z');
    $secretKey = base64_decode((string) config('audit.anchor.private_key'), true);
    $message = "resellerbytes.audit-anchor.v1\n".json_encode(
        $envelope['payload'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
    );
    $envelope['signature'] = base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    $futureCheckpoint = json_encode($envelope, JSON_THROW_ON_ERROR);
    $store = new FakeAuditCheckpointObjectStore;

    expect(fn () => (new AuditCheckpointPublisher($store, app(AuditAnchorService::class)))->publish($futureCheckpoint))
        ->toThrow(RuntimeException::class, 'audit_anchor_publish_timestamp_in_future');

    expect($store->createCalls)->toBeEmpty();
});

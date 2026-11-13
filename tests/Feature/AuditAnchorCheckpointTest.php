<?php

use App\Services\Audit\AuditAnchorService;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $keyPair = sodium_crypto_sign_keypair();
    config([
        'audit.anchor.private_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
        'audit.anchor.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
    ]);
});

function appendAuditAnchorFixtureEvent(AuditEventWriter $writer, string $eventType): void
{
    $writer->append([
        'event_type' => $eventType,
        'subject_type' => 'test',
        'subject_id' => 'anchor-fixture',
        'metadata' => ['source' => 'automated-test'],
        'occurred_at' => now(),
    ]);
}

it('exports a signed checkpoint and verifies the anchored audit-chain prefix', function () {
    appendAuditAnchorFixtureEvent(app(AuditEventWriter::class), 'test.anchor.created');

    $anchors = app(AuditAnchorService::class);
    $checkpoint = $anchors->createCheckpoint();
    $result = $anchors->verifyCheckpoint($checkpoint);

    expect($result['anchor_sequence'])->toBe(1)
        ->and($result['local_sequence'])->toBe(1)
        ->and($result['unanchored_tail'])->toBe(0)
        ->and($result['checkpoint_sha256'])->toMatch('/\A[a-f0-9]{64}\z/');
});

it('reports events appended after the external checkpoint as an unanchored tail', function () {
    $writer = app(AuditEventWriter::class);
    appendAuditAnchorFixtureEvent($writer, 'test.anchor.first');

    $anchors = app(AuditAnchorService::class);
    $checkpoint = $anchors->createCheckpoint();
    appendAuditAnchorFixtureEvent($writer, 'test.anchor.second');

    $result = $anchors->verifyCheckpoint($checkpoint);

    expect($result['anchor_sequence'])->toBe(1)
        ->and($result['local_sequence'])->toBe(2)
        ->and($result['unanchored_tail'])->toBe(1);
});

it('rejects a checkpoint whose signed payload has been modified', function () {
    appendAuditAnchorFixtureEvent(app(AuditEventWriter::class), 'test.anchor.tamper');

    $anchors = app(AuditAnchorService::class);
    $checkpoint = $anchors->createCheckpoint();
    $envelope = json_decode($checkpoint, true, 512, JSON_THROW_ON_ERROR);
    $envelope['payload']['chain_sequence'] = 99;
    $tampered = json_encode($envelope, JSON_THROW_ON_ERROR);

    expect(fn () => $anchors->verifyCheckpoint($tampered))
        ->toThrow(RuntimeException::class, 'audit_anchor_signature_invalid');
});

it('enforces the previous checkpoint digest when the verifier supplies one', function () {
    appendAuditAnchorFixtureEvent(app(AuditEventWriter::class), 'test.anchor.previous');

    $anchors = app(AuditAnchorService::class);
    $previous = str_repeat('a', 64);
    $checkpoint = $anchors->createCheckpoint($previous);

    expect(fn () => $anchors->verifyCheckpoint($checkpoint, str_repeat('b', 64)))
        ->toThrow(RuntimeException::class, 'audit_anchor_previous_checkpoint_mismatch');

    expect($anchors->verifyCheckpoint($checkpoint, $previous)['anchor_sequence'])->toBe(1);
});

it('exposes the checkpoint export artisan command', function () {
    appendAuditAnchorFixtureEvent(app(AuditEventWriter::class), 'test.anchor.command');

    expect(Artisan::call('audit:anchor:export'))->toBe(0);

    $envelope = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($envelope['payload']['chain_sequence'])->toBe(1)
        ->and($envelope['signature'])->toBeString();
});

<?php

use App\Services\Audit\AuditAnchorService;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\DB;

it('signs and verifies an audit checkpoint while holding the PostgreSQL chain head lock', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $keyPair = sodium_crypto_sign_keypair();
    config([
        'audit.anchor.private_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
        'audit.anchor.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
    ]);

    app(AuditEventWriter::class)->append([
        'event_type' => 'test.postgres.anchor',
        'subject_type' => 'test',
        'subject_id' => 'postgres-anchor',
        'metadata' => ['driver' => 'pgsql'],
        'occurred_at' => now(),
    ]);

    $anchors = app(AuditAnchorService::class);
    $checkpoint = $anchors->createCheckpoint();
    $result = $anchors->verifyCheckpoint($checkpoint);

    expect($result['anchor_sequence'])->toBe(1)
        ->and($result['local_sequence'])->toBe(1)
        ->and($result['unanchored_tail'])->toBe(0);
});

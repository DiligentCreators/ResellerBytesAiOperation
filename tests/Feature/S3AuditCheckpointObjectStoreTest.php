<?php

use App\Services\Audit\S3AuditCheckpointObjectStore;
use RuntimeException;

beforeEach(function () {
    config([
        'audit.anchor.storage.bucket' => 'audit-anchor-test',
        'audit.anchor.storage.endpoint' => 'https://s3.example.invalid',
        'audit.anchor.storage.region' => 'us-east-1',
        'audit.anchor.storage.aws_cli_binary' => 'aws',
        'filesystems.disks.s3.key' => 'test-access-key',
        'filesystems.disks.s3.secret' => 'test-secret-key',
    ]);
});

it('fails closed when the dedicated audit bucket is not configured', function () {
    config(['audit.anchor.storage.bucket' => null]);

    expect(fn () => app(S3AuditCheckpointObjectStore::class)->findImmutableObject('audit-anchors/testing/checkpoint.json'))
        ->toThrow(RuntimeException::class, 'audit_anchor_storage_bucket_not_configured');
});

it('rejects unsafe object keys before invoking the S3 client', function () {
    expect(fn () => app(S3AuditCheckpointObjectStore::class)->findImmutableObject('../private/checkpoint.json'))
        ->toThrow(RuntimeException::class, 'audit_anchor_object_key_invalid');
});

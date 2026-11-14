<?php

use App\Services\Audit\S3AuditCheckpointObjectStore;
use RuntimeException;

beforeEach(function () {
    config([
        'audit.anchor.storage.bucket' => 'audit-anchor-test',
        'audit.anchor.storage.endpoint' => 'https://s3.example.invalid',
        'audit.anchor.storage.region' => 'us-east-1',
        'audit.anchor.storage.aws_cli_binary' => 'aws',
        'audit.anchor.storage.access_key' => 'test-audit-access-key',
        'audit.anchor.storage.secret_key' => 'test-audit-secret-key',
        'filesystems.disks.s3.bucket' => 'backup-test',
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

it('refuses to reuse the operational backup bucket for audit anchors', function () {
    config(['filesystems.disks.s3.bucket' => 'audit-anchor-test']);

    expect(fn () => app(S3AuditCheckpointObjectStore::class)->findImmutableObject('audit-anchors/testing/checkpoint.json'))
        ->toThrow(RuntimeException::class, 'audit_anchor_storage_bucket_must_be_dedicated');
});

it('requires HTTPS for the external audit storage endpoint', function () {
    config(['audit.anchor.storage.endpoint' => 'http://s3.example.invalid']);

    expect(fn () => app(S3AuditCheckpointObjectStore::class)->findImmutableObject('audit-anchors/testing/checkpoint.json'))
        ->toThrow(RuntimeException::class, 'audit_anchor_storage_endpoint_invalid');
});

it('fails closed when dedicated audit storage credentials are missing', function () {
    config(['audit.anchor.storage.secret_key' => null]);

    expect(fn () => app(S3AuditCheckpointObjectStore::class)->findImmutableObject('audit-anchors/testing/checkpoint.json'))
        ->toThrow(RuntimeException::class, 'audit_anchor_storage_secret_key_not_configured');
});

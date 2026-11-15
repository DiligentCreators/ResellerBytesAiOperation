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

it('rejects endpoints containing embedded credentials', function () {
    config(['audit.anchor.storage.endpoint' => 'https://user:password@s3.example.invalid']);

    expect(fn () => app(S3AuditCheckpointObjectStore::class)->findImmutableObject('audit-anchors/testing/checkpoint.json'))
        ->toThrow(RuntimeException::class, 'audit_anchor_storage_endpoint_invalid');
});


it('creates and reads back an exact immutable S3 object version through the CLI adapter', function () {
    $script = tempnam(sys_get_temp_dir(), 'rb-audit-s3-cli-');
    $state = $script.'.object';
    if (! is_string($script)) {
        throw new RuntimeException('fake_s3_cli_script_failed');
    }

    $program = "#!/usr/bin/env php\n<?php\n".
        '$state = '.var_export($state, true).";\n".
        '$args = $argv;'."\n".
        'function optionValue(array $args, string $name): ?string { $index = array_search($name, $args, true); return $index === false ? null : ($args[$index + 1] ?? null); }'."\n".
        '$action = $args[2] ?? null;'."\n".
        'if ($action === "put-object") { $body = optionValue($args, "--body"); file_put_contents($state, file_get_contents((string) $body)); echo json_encode(["VersionId" => "test-version-1"]); exit(0); }'."\n".
        'if ($action === "head-object") { echo json_encode(["VersionId" => "test-version-1", "ObjectLockMode" => "COMPLIANCE", "ObjectLockRetainUntilDate" => "2027-11-15T00:00:00Z"]); exit(0); }'."\n".
        'if ($action === "get-object") { $index = array_search("--version-id", $args, true); $destination = $index === false ? null : ($args[$index + 2] ?? null); file_put_contents((string) $destination, file_get_contents($state)); echo json_encode(["VersionId" => "test-version-1"]); exit(0); }'."\n".
        'fwrite(STDERR, "unsupported fake operation"); exit(2);'."\n";
    file_put_contents($script, $program);
    chmod($script, 0700);
    config(['audit.anchor.storage.aws_cli_binary' => $script]);

    try {
        $contents = '{"checkpoint":"signed-test-payload"}';
        $created = app(S3AuditCheckpointObjectStore::class)->createImmutableIfAbsent(
            'audit-anchors/test/checkpoint.json',
            $contents,
            new DateTimeImmutable('2027-11-14T00:00:00Z'),
        );
        $readBack = app(S3AuditCheckpointObjectStore::class)->readImmutableVersion(
            'audit-anchors/test/checkpoint.json',
            $created->versionId,
        );

        expect($created->versionId)->toBe('test-version-1')
            ->and($created->retentionMode)->toBe('COMPLIANCE')
            ->and($readBack->versionId)->toBe('test-version-1')
            ->and($readBack->contents)->toBe($contents)
            ->and($readBack->retentionMode)->toBe('COMPLIANCE');
    } finally {
        @unlink($script);
        @unlink($state);
    }
});

it('bounds external checkpoint downloads before loading them into memory', function () {
    $script = tempnam(sys_get_temp_dir(), 'rb-audit-s3-oversized-');
    if (! is_string($script)) {
        throw new RuntimeException('fake_s3_cli_script_failed');
    }

    $program = "#!/usr/bin/env php\n<?php\n".
        '$args = $argv;'. "\n".
        '$action = $args[2] ?? null;'. "\n".
        'if ($action === "head-object") { echo json_encode(["VersionId" => "test-version-1", "ObjectLockMode" => "COMPLIANCE", "ObjectLockRetainUntilDate" => "2027-11-15T00:00:00Z"]); exit(0); }'. "\n".
        'if ($action === "get-object") { $index = array_search("--version-id", $args, true); $destination = $index === false ? null : ($args[$index + 2] ?? null); file_put_contents((string) $destination, str_repeat("x", 65537)); echo json_encode(["VersionId" => "test-version-1"]); exit(0); }'. "\n".
        'fwrite(STDERR, "unsupported fake operation"); exit(2);'. "\n";
    file_put_contents($script, $program);
    chmod($script, 0700);
    config(['audit.anchor.storage.aws_cli_binary' => $script]);

    try {
        expect(fn () => app(S3AuditCheckpointObjectStore::class)->readImmutableVersion(
            'audit-anchors/test/checkpoint.json',
            'test-version-1',
        ))->toThrow(RuntimeException::class, 'audit_anchor_object_readback_oversized');
    } finally {
        @unlink($script);
    }
});

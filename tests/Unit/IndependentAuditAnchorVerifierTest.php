<?php

use Symfony\Component\Process\Process;

function createIndependentAuditAnchorFixture(?string $previous = null, ?string $createdAt = null): array
{
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
    $payload = [
        'anchor_version' => 1,
        'environment' => 'testing',
        'created_at' => $createdAt ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z'),
        'chain_sequence' => 7,
        'chain_head_hash' => str_repeat('a', 64),
        'previous_checkpoint_sha256' => $previous,
    ];
    $message = "resellerbytes.audit-anchor.v1\n".json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    $envelope = [
        'payload' => $payload,
        'signature' => base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keyPair))),
    ];
    $path = tempnam(sys_get_temp_dir(), 'rb-anchor-');
    file_put_contents($path, json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return [$path, $publicKey, $envelope];
}

function runIndependentAuditAnchorVerifier(string $checkpoint, string $publicKey, string $environment = 'testing', ?string $previous = null, ?int $maxAgeMinutes = null): Process
{
    $root = dirname(__DIR__, 2);
    $command = [PHP_BINARY, $root.'/tools/verify-audit-anchor.php', $checkpoint];
    if ($previous !== null) {
        $command[] = $previous;
    }
    if ($maxAgeMinutes !== null) {
        $command[] = '--max-age-minutes='.$maxAgeMinutes;
    }

    $process = new Process($command, $root, [
        'AUDIT_ANCHOR_PUBLIC_KEY' => $publicKey,
        'AUDIT_ANCHOR_EXPECTED_ENVIRONMENT' => $environment,
    ]);
    $process->run();

    return $process;
}

it('independently verifies a signed checkpoint using only the public key', function () {
    [$path, $publicKey] = createIndependentAuditAnchorFixture();

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey);

        expect($process->isSuccessful())->toBeTrue()
            ->and(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['verified'])->toBeTrue();
    } finally {
        @unlink($path);
    }
});

it('rejects a checkpoint with a modified signed payload', function () {
    [$path, $publicKey, $envelope] = createIndependentAuditAnchorFixture();
    $envelope['payload']['chain_sequence'] = 8;
    file_put_contents($path, json_encode($envelope, JSON_THROW_ON_ERROR));

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('signature is invalid');
    } finally {
        @unlink($path);
    }
});

it('rejects a checkpoint from a different environment', function () {
    [$path, $publicKey] = createIndependentAuditAnchorFixture();

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey, 'production');

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('environment does not match');
    } finally {
        @unlink($path);
    }
});

it('rejects a checkpoint whose timestamp is too far in the future', function () {
    $future = (new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
    [$path, $publicKey] = createIndependentAuditAnchorFixture(null, $future);

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('too far in the future');
    } finally {
        @unlink($path);
    }
});

it('rejects an invalid calendar timestamp even when its signature is valid', function () {
    [$path, $publicKey] = createIndependentAuditAnchorFixture(null, '2026-02-31T09:00:00.000000Z');

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('not a valid canonical UTC timestamp');
    } finally {
        @unlink($path);
    }
});

it('enforces an explicitly configured maximum checkpoint age', function () {
    $stale = (new DateTimeImmutable('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
    [$path, $publicKey] = createIndependentAuditAnchorFixture(null, $stale);

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey, 'testing', null, 60);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('exceeds the configured maximum age');
    } finally {
        @unlink($path);
    }
});

it('enforces the expected previous checkpoint digest when supplied', function () {
    [$path, $publicKey] = createIndependentAuditAnchorFixture(str_repeat('b', 64));

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey, 'testing', str_repeat('c', 64));

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('previous checkpoint digest does not match');
    } finally {
        @unlink($path);
    }
});

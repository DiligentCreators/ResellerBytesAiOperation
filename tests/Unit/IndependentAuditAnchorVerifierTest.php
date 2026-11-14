<?php

use Symfony\Component\Process\Process;

function runIndependentAuditAnchorVerifier(string $checkpoint, string $publicKey, string $environment = 'testing', ?string $previous = null): Process
{
    $command = [PHP_BINARY, base_path('tools/verify-audit-anchor.php'), $checkpoint];
    if ($previous !== null) {
        $command[] = $previous;
    }

    $process = new Process($command, base_path(), [
        'AUDIT_ANCHOR_PUBLIC_KEY' => $publicKey,
        'AUDIT_ANCHOR_EXPECTED_ENVIRONMENT' => $environment,
    ]);
    $process->run();

    return $process;
}

function createIndependentAuditAnchorFixture(): array
{
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
    $payload = [
        'anchor_version' => 1,
        'environment' => 'testing',
        'created_at' => '2026-11-14T09:00:00.000000Z',
        'chain_sequence' => 7,
        'chain_head_hash' => str_repeat('a', 64),
        'previous_checkpoint_sha256' => null,
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

it('enforces the expected previous checkpoint digest when supplied', function () {
    [$path, $publicKey, $envelope] = createIndependentAuditAnchorFixture();
    $keyPair = sodium_crypto_sign_keypair();
    $payload = $envelope['payload'];
    $payload['previous_checkpoint_sha256'] = str_repeat('b', 64);
    $message = "resellerbytes.audit-anchor.v1\n".json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    // Re-sign with the fixture's private key by rebuilding the fixture with a linked predecessor.
    @unlink($path);
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));
    $payload['previous_checkpoint_sha256'] = str_repeat('b', 64);
    $message = "resellerbytes.audit-anchor.v1\n".json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
    $envelope = [
        'payload' => $payload,
        'signature' => base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keyPair))),
    ];
    $path = tempnam(sys_get_temp_dir(), 'rb-anchor-');
    file_put_contents($path, json_encode($envelope, JSON_THROW_ON_ERROR));

    try {
        $process = runIndependentAuditAnchorVerifier($path, $publicKey, 'testing', str_repeat('c', 64));

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('previous checkpoint digest does not match');
    } finally {
        @unlink($path);
    }
});

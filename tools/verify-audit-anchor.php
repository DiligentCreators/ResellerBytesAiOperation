<?php

declare(strict_types=1);

const AUDIT_ANCHOR_DOMAIN = "resellerbytes.audit-anchor.v1\n";

function fail(string $reason): never
{
    fwrite(STDERR, "Audit checkpoint verification failed: {$reason}\n");
    exit(1);
}

$checkpointPath = $argv[1] ?? '';
if ($checkpointPath === '' || ! is_file($checkpointPath) || ! is_readable($checkpointPath)) {
    fail('a readable checkpoint file is required');
}

$publicKeyValue = getenv('AUDIT_ANCHOR_PUBLIC_KEY');
$expectedEnvironment = getenv('AUDIT_ANCHOR_EXPECTED_ENVIRONMENT');
if (! is_string($publicKeyValue) || $publicKeyValue === '') {
    fail('AUDIT_ANCHOR_PUBLIC_KEY is not configured');
}
if (! is_string($expectedEnvironment) || $expectedEnvironment === '') {
    fail('AUDIT_ANCHOR_EXPECTED_ENVIRONMENT is not configured');
}

$publicKey = base64_decode($publicKeyValue, true);
if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
    fail('public key encoding or length is invalid');
}

$bytes = file_get_contents($checkpointPath);
if (! is_string($bytes)) {
    fail('checkpoint file could not be read');
}

try {
    $envelope = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fail('checkpoint JSON is invalid');
}

if (! is_array($envelope) || ! is_array($envelope['payload'] ?? null) || ! is_string($envelope['signature'] ?? null)) {
    fail('checkpoint envelope is invalid');
}

$payload = $envelope['payload'];
$previous = $payload['previous_checkpoint_sha256'] ?? null;
if (($payload['anchor_version'] ?? null) !== 1
    || ($payload['environment'] ?? null) !== $expectedEnvironment
    || ! is_string($payload['created_at'] ?? null)
    || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $payload['created_at']) !== 1
    || ! is_int($payload['chain_sequence'] ?? null)
    || $payload['chain_sequence'] < 0
    || ! is_string($payload['chain_head_hash'] ?? null)
    || preg_match('/\A[a-f0-9]{64}\z/', $payload['chain_head_hash']) !== 1
    || ($previous !== null && (! is_string($previous) || preg_match('/\A[a-f0-9]{64}\z/', $previous) !== 1))) {
    fail('checkpoint payload fields are invalid or environment does not match');
}

$expectedPrevious = null;
$maxAgeMinutes = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--max-age-minutes=')) {
        if ($maxAgeMinutes !== null) {
            fail('maximum age option was supplied more than once');
        }

        $rawMaxAge = substr($argument, strlen('--max-age-minutes='));
        if (preg_match('/\A[1-9][0-9]*\z/', $rawMaxAge) !== 1 || filter_var($rawMaxAge, FILTER_VALIDATE_INT) === false) {
            fail('maximum age must be a positive integer number of minutes');
        }
        $maxAgeMinutes = (int) $rawMaxAge;

        continue;
    }

    if ($expectedPrevious !== null || preg_match('/\A[a-f0-9]{64}\z/', $argument) !== 1) {
        fail('optional arguments must be one prior checkpoint SHA-256 and/or --max-age-minutes=N');
    }
    $expectedPrevious = $argument;
}
if ($expectedPrevious !== null && (! is_string($previous) || ! hash_equals($expectedPrevious, $previous))) {
    fail('previous checkpoint digest does not match');
}

$checkpointTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $payload['created_at'], new DateTimeZone('UTC'));
$dateErrors = DateTimeImmutable::getLastErrors();
if ($checkpointTime === false
    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
    || $checkpointTime->format('Y-m-d\TH:i:s.u\Z') !== $payload['created_at']) {
    fail('checkpoint timestamp is not a valid canonical UTC timestamp');
}
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$ageSeconds = $now->getTimestamp() - $checkpointTime->getTimestamp();
if ($ageSeconds < -300) {
    fail('checkpoint timestamp is too far in the future');
}
if ($maxAgeMinutes !== null && $ageSeconds > $maxAgeMinutes * 60) {
    fail('checkpoint exceeds the configured maximum age');
}

$signature = base64_decode($envelope['signature'], true);
if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
    fail('signature encoding is invalid');
}

try {
    $message = AUDIT_ANCHOR_DOMAIN.json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
} catch (JsonException) {
    fail('checkpoint payload cannot be canonicalized');
}

if (! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
    fail('signature is invalid');
}

echo json_encode([
    'verified' => true,
    'environment' => $payload['environment'],
    'created_at' => $payload['created_at'],
    'chain_sequence' => $payload['chain_sequence'],
    'chain_head_hash' => $payload['chain_head_hash'],
    'checkpoint_sha256' => hash('sha256', $bytes),
    'age_seconds' => max(0, $ageSeconds),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;

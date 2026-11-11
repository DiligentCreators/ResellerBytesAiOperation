<?php

use ResellerBytesAiOps\CredentialsFile;
use RuntimeException;

require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CredentialsFile.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertCredentialsFailure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        return;
    }

    throw new RuntimeException($message);
}

$base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'resellerbytes-ai-ops-'.bin2hex(random_bytes(8));
$whmcsRoot = $base.DIRECTORY_SEPARATOR.'whmcs';
$documentRoot = $whmcsRoot;
$credentialsPath = $base.DIRECTORY_SEPARATOR.'credentials.json';
$insidePath = $whmcsRoot.DIRECTORY_SEPARATOR.'credentials.json';

mkdir($base, 0700, true);
mkdir($whmcsRoot, 0700, true);

$credentials = [
    'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
    'secret' => str_repeat('a', 64),
];

$json = json_encode($credentials, JSON_THROW_ON_ERROR);
file_put_contents($credentialsPath, $json);
chmod($credentialsPath, 0600);

try {
    $loaded = CredentialsFile::load(
        $credentialsPath,
        $whmcsRoot,
        $documentRoot,
    );

    assertSameValue($credentials['integration_id'], $loaded['integration_id'], 'Integration ID did not load.');
    assertSameValue($credentials['secret'], $loaded['secret'], 'Secret did not load.');

    file_put_contents($insidePath, $json);
    chmod($insidePath, 0600);

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($insidePath, $whmcsRoot, $documentRoot),
        'Credentials file inside the web root was accepted.',
    );

    chmod($credentialsPath, 0400);

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($credentialsPath, $whmcsRoot, $documentRoot),
        'Credentials file without the required 0600 mode was accepted.',
    );

    chmod($credentialsPath, 0644);

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($credentialsPath, $whmcsRoot, $documentRoot),
        'World-readable credentials file was accepted.',
    );
} finally {
    if (is_file($insidePath)) {
        unlink($insidePath);
    }

    if (is_file($credentialsPath)) {
        unlink($credentialsPath);
    }

    if (is_dir($whmcsRoot)) {
        rmdir($whmcsRoot);
    }

    if (is_dir($base)) {
        rmdir($base);
    }
}

echo "WHMCS addon credential file tests passed.\n";

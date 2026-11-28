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
$symlinkPath = $whmcsRoot.DIRECTORY_SEPARATOR.'credentials-link.json';
$externalSymlinkPath = $base.DIRECTORY_SEPARATOR.'external-credentials-link.json';

mkdir($base, 0700, true);
mkdir($whmcsRoot, 0700, true);

$credentials = [
    'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
    'secret' => str_repeat('a', 64),
];

$json = json_encode($credentials, JSON_THROW_ON_ERROR);
file_put_contents($credentialsPath, $json);
chmod($credentialsPath, 0600);
clearstatcache(true, $credentialsPath);

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
    clearstatcache(true, $insidePath);

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($insidePath, $whmcsRoot, $documentRoot),
        'Credentials file inside the web root was accepted.',
    );

    // CLI fallback: RuntimeRoots returns the WHMCS root as documentRoot when DOCUMENT_ROOT is empty.
    assertCredentialsFailure(
        static fn () => CredentialsFile::load($insidePath, $whmcsRoot, $whmcsRoot),
        'CLI fallback: credentials file inside the WHMCS root was accepted when documentRoot equals whmcsRoot.',
    );

    $loadedCliFallback = CredentialsFile::load($credentialsPath, $whmcsRoot, $whmcsRoot);
    assertSameValue(
        $credentials['integration_id'],
        $loadedCliFallback['integration_id'],
        'CLI fallback: external credentials file was rejected when documentRoot equals whmcsRoot.',
    );
    assertSameValue(
        $credentials['secret'],
        $loadedCliFallback['secret'],
        'CLI fallback: external credentials secret did not load when documentRoot equals whmcsRoot.',
    );

    if (! symlink($credentialsPath, $symlinkPath)) {
        throw new RuntimeException('Could not create symlink test fixture.');
    }

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($symlinkPath, $whmcsRoot, $documentRoot),
        'Credentials symlink inside the web root was accepted.',
    );

    unlink($symlinkPath);

    if (! symlink($credentialsPath, $externalSymlinkPath)) {
        throw new RuntimeException('Could not create external symlink test fixture.');
    }

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($externalSymlinkPath, $whmcsRoot, $documentRoot),
        'Credentials symlink outside the web root was accepted.',
    );

    unlink($externalSymlinkPath);

    chmod($credentialsPath, 0400);
    clearstatcache(true, $credentialsPath);

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($credentialsPath, $whmcsRoot, $documentRoot),
        'Credentials file without the required 0600 mode was accepted.',
    );

    chmod($credentialsPath, 0644);
    clearstatcache(true, $credentialsPath);

    assertCredentialsFailure(
        static fn () => CredentialsFile::load($credentialsPath, $whmcsRoot, $documentRoot),
        'World-readable credentials file was accepted.',
    );

    chmod($credentialsPath, 0600);
    clearstatcache(true, $credentialsPath);
    file_put_contents($credentialsPath, '{not-json');
    try {
        CredentialsFile::load($credentialsPath, $whmcsRoot, $documentRoot);
        throw new RuntimeException('Invalid JSON credentials file was accepted.');
    } catch (RuntimeException $exception) {
        assertSameValue('credentials_file_invalid_json', $exception->getMessage(), 'Invalid JSON must use credentials_file_invalid_json.');
    }

    file_put_contents($credentialsPath, json_encode(['integration_id' => $credentials['integration_id']], JSON_THROW_ON_ERROR));
    chmod($credentialsPath, 0600);
    clearstatcache(true, $credentialsPath);
    try {
        CredentialsFile::load($credentialsPath, $whmcsRoot, $documentRoot);
        throw new RuntimeException('Invalid structure credentials file was accepted.');
    } catch (RuntimeException $exception) {
        assertSameValue('credentials_file_invalid_structure', $exception->getMessage(), 'Missing secret must use credentials_file_invalid_structure.');
    }

    file_put_contents($credentialsPath, json_encode([
        'integration_id' => 'not-a-uuid',
        'secret' => $credentials['secret'],
    ], JSON_THROW_ON_ERROR));
    chmod($credentialsPath, 0600);
    clearstatcache(true, $credentialsPath);
    try {
        CredentialsFile::load($credentialsPath, $whmcsRoot, $documentRoot);
        throw new RuntimeException('Invalid values credentials file was accepted.');
    } catch (RuntimeException $exception) {
        assertSameValue('credentials_file_invalid_values', $exception->getMessage(), 'Invalid UUID must use credentials_file_invalid_values.');
    }
} finally {
    if (is_link($symlinkPath)) {
        unlink($symlinkPath);
    }

    if (is_link($externalSymlinkPath)) {
        unlink($externalSymlinkPath);
    }

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

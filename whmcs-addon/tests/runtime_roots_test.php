<?php

use ResellerBytesAiOps\RuntimeRoots;

require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/RuntimeRoots.php';

function assertRuntimeRootsSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).'.');
    }
}

$whmcsRoot = '/var/www/whmcs';
$webDocumentRoot = '/var/www/html';

assertRuntimeRootsSame(
    $webDocumentRoot,
    RuntimeRoots::documentRootFor('apache2handler', ['DOCUMENT_ROOT' => $webDocumentRoot], $whmcsRoot),
    'Web request with DOCUMENT_ROOT must return DOCUMENT_ROOT.',
);

assertRuntimeRootsSame(
    $whmcsRoot,
    RuntimeRoots::documentRootFor('cli', ['DOCUMENT_ROOT' => ''], $whmcsRoot),
    'CLI with empty DOCUMENT_ROOT must fall back to the WHMCS root.',
);

assertRuntimeRootsSame(
    $whmcsRoot,
    RuntimeRoots::documentRootFor('cli', [], $whmcsRoot),
    'CLI with missing DOCUMENT_ROOT key must fall back to the WHMCS root.',
);

assertRuntimeRootsSame(
    $whmcsRoot,
    RuntimeRoots::documentRootFor('phpdbg', ['DOCUMENT_ROOT' => ''], $whmcsRoot),
    'phpdbg with empty DOCUMENT_ROOT must fall back to the WHMCS root.',
);

assertRuntimeRootsSame(
    '',
    RuntimeRoots::documentRootFor('apache2handler', ['DOCUMENT_ROOT' => ''], $whmcsRoot),
    'Non-CLI with empty DOCUMENT_ROOT must return empty.',
);

assertRuntimeRootsSame(
    '',
    RuntimeRoots::documentRootFor('cli', ['DOCUMENT_ROOT' => ''], ''),
    'CLI with empty WHMCS root must return empty.',
);

echo "WHMCS addon runtime roots tests passed.\n";

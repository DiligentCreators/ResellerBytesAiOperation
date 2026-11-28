<?php

namespace ResellerBytesAiOps;

/**
 * Resolves document-root fallbacks for credential path checks.
 *
 * Pure static helpers — no WHMCS globals. Under PHP CLI cron, DOCUMENT_ROOT is
 * typically empty; callers may pass ROOTDIR as $whmcsRoot so CredentialsFile
 * still has two absolute roots to compare against.
 */
final class RuntimeRoots
{
    /**
     * @param  array<string, mixed>  $server
     */
    public static function documentRootFor(string $sapi, array $server, string $whmcsRoot): string
    {
        $documentRoot = $server['DOCUMENT_ROOT'] ?? null;

        if (is_string($documentRoot) && $documentRoot !== '') {
            return $documentRoot;
        }

        if (($sapi === 'cli' || $sapi === 'phpdbg') && $whmcsRoot !== '') {
            return $whmcsRoot;
        }

        return '';
    }
}

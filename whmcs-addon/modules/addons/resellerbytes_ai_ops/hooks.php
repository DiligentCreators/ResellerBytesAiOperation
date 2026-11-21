<?php

use ResellerBytesAiOps\CredentialsFile;
use ResellerBytesAiOps\SignedApiClient;
use WHMCS\Database\Capsule;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/CredentialsFile.php';
require_once __DIR__.'/lib/SignedApiClient.php';

/**
 * DailyCronJob sets a process flag so AfterCronJob can emit cron_kind=daily once.
 */
add_hook('DailyCronJob', 1, static function (): void {
    $GLOBALS['resellerbytes_ai_ops_daily_cron'] = true;
});

add_hook('AfterCronJob', 1, static function (): void {
    try {
        $credentialsPath = '';
        if (class_exists(Capsule::class)) {
            $value = Capsule::table('tbladdonmodules')
                ->where('module', 'resellerbytes_ai_ops')
                ->where('setting', 'credentials_file')
                ->value('value');
            $credentialsPath = is_string($value) ? $value : '';
        }

        if ($credentialsPath === '') {
            return;
        }

        $credentials = CredentialsFile::load(
            $credentialsPath,
            defined('ROOTDIR') ? (string) ROOTDIR : '',
            (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        );

        $whmcsVersion = 'unknown';
        if (class_exists(Capsule::class)) {
            $version = Capsule::table('tblconfiguration')
                ->where('setting', 'Version')
                ->value('value');
            if (is_string($version) && $version !== '') {
                $whmcsVersion = substr($version, 0, 32);
            }
        }

        $cronKind = ! empty($GLOBALS['resellerbytes_ai_ops_daily_cron']) ? 'daily' : 'system';
        $GLOBALS['resellerbytes_ai_ops_daily_cron'] = false;

        (new SignedApiClient)->heartbeat($credentials, [
            'addon_version' => SignedApiClient::ADDON_VERSION,
            'whmcs_version' => $whmcsVersion,
            'cli_php_version' => PHP_VERSION,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'cron_kind' => $cronKind,
        ]);
    } catch (Throwable $exception) {
        // Heartbeat failures must never interrupt WHMCS cron.
    }
});

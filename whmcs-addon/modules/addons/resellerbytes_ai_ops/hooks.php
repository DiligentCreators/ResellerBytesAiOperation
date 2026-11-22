<?php

use ResellerBytesAiOps\CredentialsFile;
use ResellerBytesAiOps\CronMonitor;
use ResellerBytesAiOps\FailureCode;
use ResellerBytesAiOps\RuntimeRoots;
use ResellerBytesAiOps\SignedApiClient;
use ResellerBytesAiOps\WhmcsActivityLogger;
use WHMCS\Database\Capsule;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

require_once __DIR__.'/lib/CredentialsFile.php';
require_once __DIR__.'/lib/RuntimeRoots.php';
require_once __DIR__.'/lib/FailureCode.php';
require_once __DIR__.'/lib/WhmcsDataSource.php';
require_once __DIR__.'/lib/CapsuleWhmcsDataSource.php';
require_once __DIR__.'/lib/SnapshotRedactor.php';
require_once __DIR__.'/lib/DiagnosticCollector.php';
require_once __DIR__.'/lib/SignedApiClient.php';
require_once __DIR__.'/lib/LocalApiClient.php';
require_once __DIR__.'/lib/WhmcsLocalApiClient.php';
require_once __DIR__.'/lib/ActivityLogger.php';
require_once __DIR__.'/lib/WhmcsActivityLogger.php';
require_once __DIR__.'/lib/FakeLocalApiClient.php';
require_once __DIR__.'/lib/RemediationRunner.php';
require_once __DIR__.'/lib/CronMonitor.php';

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

        $whmcsRoot = defined('ROOTDIR') ? (string) ROOTDIR : '';
        $credentials = CredentialsFile::load(
            $credentialsPath,
            $whmcsRoot,
            RuntimeRoots::documentRootFor(PHP_SAPI, $_SERVER, $whmcsRoot),
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

        $lockDirectory = '';
        if (defined('ROOTDIR')) {
            $candidate = rtrim(str_replace('\\', '/', (string) ROOTDIR), '/').'/attachments';
            if (is_dir($candidate.'/temp')) {
                $lockDirectory = $candidate.'/temp';
            } elseif (is_dir($candidate)) {
                $lockDirectory = $candidate;
            }
        }

        if ($lockDirectory === '') {
            $lockDirectory = sys_get_temp_dir();
        }

        (new CronMonitor)->run($credentials, [
            'addon_version' => SignedApiClient::ADDON_VERSION,
            'whmcs_version' => $whmcsVersion,
            'cli_php_version' => PHP_VERSION,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'cron_kind' => $cronKind,
        ], $lockDirectory);

        $GLOBALS['resellerbytes_ai_ops_last_hook_failure'] = null;
    } catch (Throwable $exception) {
        // Heartbeat / auto-collect / remediation failures must never interrupt WHMCS cron.
        try {
            $code = FailureCode::sanitize($exception->getMessage());
            $previousFailure = $GLOBALS['resellerbytes_ai_ops_last_hook_failure'] ?? null;
            if ($previousFailure !== $code) {
                (new WhmcsActivityLogger)->log('ResellerBytes AI Ops cron hook failed: '.$code);
            }
            $GLOBALS['resellerbytes_ai_ops_last_hook_failure'] = $code;
        } catch (Throwable $ignored) {
            // Activity logging must never interrupt WHMCS cron either.
        }
    }
});

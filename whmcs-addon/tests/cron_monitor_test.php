<?php

namespace ResellerBytesAiOps {
    use stdClass;

    if (! function_exists(__NAMESPACE__.'\\curl_init')) {
        function curl_init(string $url): object
        {
            $GLOBALS['rb_mock_curl_urls'][] = $url;

            return new stdClass;
        }

        function curl_setopt_array(object $handle, array $options): bool
        {
            $GLOBALS['rb_mock_curl_options_list'][] = $options;

            return true;
        }

        function curl_exec(object $handle): bool
        {
            $throwable = $GLOBALS['rb_mock_curl_throwable'] ?? null;
            if ($throwable instanceof \Throwable) {
                throw $throwable;
            }

            $index = count($GLOBALS['rb_mock_curl_options_list'] ?? []) - 1;
            $responseBody = $GLOBALS['rb_mock_response_bodies'][$index]
                ?? $GLOBALS['rb_mock_response_body']
                ?? '{"data":{"accepted":true,"auto_collect_due":false,"pending_approved_actions":false}}';
            $callback = $GLOBALS['rb_mock_curl_options_list'][$index][CURLOPT_WRITEFUNCTION];

            return $callback($handle, $responseBody) === strlen($responseBody);
        }

        function curl_getinfo(object $handle, int $option): int
        {
            $index = count($GLOBALS['rb_mock_curl_options_list'] ?? []) - 1;

            return $GLOBALS['rb_mock_http_statuses'][$index] ?? ($GLOBALS['rb_mock_http_status'] ?? 202);
        }

        function curl_errno(object $handle): int
        {
            return (int) ($GLOBALS['rb_mock_curl_errno'] ?? 0);
        }

        function curl_close(object $handle): void {}
    }
}

namespace {
    use ResellerBytesAiOps\CronMonitor;
    use ResellerBytesAiOps\SignedApiClient;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/FailureCode.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/Text.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/Identifier.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/WhmcsDataSource.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CapsuleWhmcsDataSource.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SnapshotRedactor.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/DiagnosticCollector.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/HttpTransport.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CurlHttpTransport.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/ApiClientException.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SignedApiClient.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/LocalApiClient.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/WhmcsLocalApiClient.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/ActivityLogger.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/WhmcsActivityLogger.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/FakeLocalApiClient.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/RemediationRunner.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CronMonitor.php';

    function assertCronCondition(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    $lockDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rb_cron_monitor_'.bin2hex(random_bytes(4));
    mkdir($lockDir);

    $GLOBALS['rb_mock_curl_urls'] = [];
    $GLOBALS['rb_mock_curl_options_list'] = [];
    $GLOBALS['rb_mock_response_bodies'] = [
        '{"data":{"accepted":true,"auto_collect_due":false,"pending_approved_actions":false}}',
    ];
    $GLOBALS['rb_mock_http_statuses'] = [202];
    $GLOBALS['rb_activity_logs'] = [];

    $monitor = new CronMonitor(new SignedApiClient, static function (string $message): void {
        $GLOBALS['rb_activity_logs'][] = $message;
    });

    $monitor->run([
        'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
        'secret' => str_repeat('b', 64),
    ], [
        'addon_version' => SignedApiClient::ADDON_VERSION,
        'whmcs_version' => '8.13.7',
        'cli_php_version' => '8.1.34',
        'sent_at' => '2026-11-22T01:00:00Z',
        'cron_kind' => 'system',
    ], $lockDir);

    assertCronCondition(count($GLOBALS['rb_mock_curl_urls']) === 1, 'Expected a single heartbeat request.');
    assertCronCondition(
        str_ends_with($GLOBALS['rb_mock_curl_urls'][0], '/api/v1/whmcs/heartbeats'),
        'Cron monitor did not post to the heartbeat endpoint.',
    );
    assertCronCondition(
        ($GLOBALS['rb_mock_curl_options_list'][0][CURLOPT_TIMEOUT] ?? null) === 5,
        'Heartbeat timeout was not shortened for cron.',
    );
    assertCronCondition($GLOBALS['rb_activity_logs'] === [], 'Successful cron should not log activity.');

    // Failure should log once, then silence identical repeats.
    $GLOBALS['rb_mock_curl_urls'] = [];
    $GLOBALS['rb_mock_curl_options_list'] = [];
    $GLOBALS['rb_mock_response_bodies'] = ['not-json'];
    $GLOBALS['rb_mock_http_statuses'] = [500];
    $GLOBALS['rb_activity_logs'] = [];
    $GLOBALS['resellerbytes_ai_ops_last_cron_failure'] = null;

    $monitor->run([
        'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
        'secret' => str_repeat('b', 64),
    ], [
        'addon_version' => SignedApiClient::ADDON_VERSION,
        'whmcs_version' => '8.13.7',
        'cli_php_version' => '8.1.34',
        'sent_at' => '2026-11-22T01:05:00Z',
        'cron_kind' => 'system',
    ], $lockDir);

    assertCronCondition(count($GLOBALS['rb_activity_logs']) === 1, 'First failure should log once.');
    assertCronCondition(
        $GLOBALS['rb_activity_logs'][0] === 'ResellerBytes AI Ops cron monitor failed: http_status_server_error',
        'Snake_case failure codes must be logged as-is.',
    );

    $GLOBALS['rb_mock_curl_urls'] = [];
    $GLOBALS['rb_mock_curl_options_list'] = [];
    $GLOBALS['rb_activity_logs'] = [];
    $monitor->run([
        'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
        'secret' => str_repeat('b', 64),
    ], [
        'addon_version' => SignedApiClient::ADDON_VERSION,
        'whmcs_version' => '8.13.7',
        'cli_php_version' => '8.1.34',
        'sent_at' => '2026-11-22T01:10:00Z',
        'cron_kind' => 'system',
    ], $lockDir);

    assertCronCondition($GLOBALS['rb_activity_logs'] === [], 'Repeated identical failures must not spam the activity log.');

    // Dirty Exception messages (e.g. PDOException) must be sanitized.
    $dirtyMessage = 'SQLSTATE[HY000] General error: SELECT * FROM secrets at /var/www/secret/path';
    $GLOBALS['rb_mock_curl_urls'] = [];
    $GLOBALS['rb_mock_curl_options_list'] = [];
    $GLOBALS['rb_mock_response_bodies'] = [];
    $GLOBALS['rb_mock_http_statuses'] = [];
    $GLOBALS['rb_mock_curl_throwable'] = new Exception($dirtyMessage);
    $GLOBALS['rb_activity_logs'] = [];
    $GLOBALS['resellerbytes_ai_ops_last_cron_failure'] = null;

    $monitor->run([
        'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
        'secret' => str_repeat('b', 64),
    ], [
        'addon_version' => SignedApiClient::ADDON_VERSION,
        'whmcs_version' => '8.13.7',
        'cli_php_version' => '8.1.34',
        'sent_at' => '2026-11-22T01:15:00Z',
        'cron_kind' => 'system',
    ], $lockDir);

    assertCronCondition(count($GLOBALS['rb_activity_logs']) === 1, 'Dirty cron failures must log once.');
    assertCronCondition(
        $GLOBALS['rb_activity_logs'][0] === 'ResellerBytes AI Ops cron monitor failed: unexpected_error',
        'Dirty exception messages must be logged as unexpected_error.',
    );
    assertCronCondition(
        ! str_contains($GLOBALS['rb_activity_logs'][0], 'SQLSTATE'),
        'Cron failure log must never contain SQLSTATE text.',
    );
    assertCronCondition(
        ! str_contains($GLOBALS['rb_activity_logs'][0], '/var/www/secret/path'),
        'Cron failure log must never contain filesystem paths.',
    );
    assertCronCondition(
        ! str_contains($GLOBALS['rb_activity_logs'][0], 'SELECT'),
        'Cron failure log must never contain SQL text.',
    );

    $GLOBALS['rb_mock_curl_urls'] = [];
    $GLOBALS['rb_mock_curl_options_list'] = [];
    $GLOBALS['rb_activity_logs'] = [];
    $monitor->run([
        'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
        'secret' => str_repeat('b', 64),
    ], [
        'addon_version' => SignedApiClient::ADDON_VERSION,
        'whmcs_version' => '8.13.7',
        'cli_php_version' => '8.1.34',
        'sent_at' => '2026-11-22T01:20:00Z',
        'cron_kind' => 'system',
    ], $lockDir);

    assertCronCondition($GLOBALS['rb_activity_logs'] === [], 'Identical sanitized unexpected_error codes must not spam the activity log.');
    $GLOBALS['rb_mock_curl_throwable'] = null;

    @unlink($lockDir.DIRECTORY_SEPARATOR.'resellerbytes_ai_ops_cron.lock');
    @rmdir($lockDir);

    echo "WHMCS addon cron monitor tests passed.\n";
}

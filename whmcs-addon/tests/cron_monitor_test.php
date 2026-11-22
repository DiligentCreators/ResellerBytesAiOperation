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

        function curl_close(object $handle): void {}
    }
}

namespace {
    use ResellerBytesAiOps\CronMonitor;
    use ResellerBytesAiOps\SignedApiClient;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/WhmcsDataSource.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CapsuleWhmcsDataSource.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SnapshotRedactor.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/DiagnosticCollector.php';
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

    @unlink($lockDir.DIRECTORY_SEPARATOR.'resellerbytes_ai_ops_cron.lock');
    @rmdir($lockDir);

    echo "WHMCS addon cron monitor tests passed.\n";
}

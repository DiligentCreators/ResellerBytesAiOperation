<?php

namespace ResellerBytesAiOps {
    use stdClass;

    if (! function_exists(__NAMESPACE__.'\\curl_init')) {
        function curl_init(string $url): object
        {
            $GLOBALS['rb_mock_curl_url'] = $url;

            return new stdClass;
        }

        function curl_setopt_array(object $handle, array $options): bool
        {
            $GLOBALS['rb_mock_curl_options'] = $options;

            return true;
        }

        function curl_exec(object $handle): bool
        {
            if (($GLOBALS['rb_mock_curl_exec_result'] ?? true) !== true) {
                return false;
            }

            $responseBody = $GLOBALS['rb_mock_response_body'] ?? '{"data":{"accepted":true}}';
            $callback = $GLOBALS['rb_mock_curl_options'][CURLOPT_WRITEFUNCTION];

            return $callback($handle, $responseBody) === strlen($responseBody);
        }

        function curl_getinfo(object $handle, int $option): int
        {
            return $GLOBALS['rb_mock_http_status'] ?? 202;
        }

        function curl_close(object $handle): void {}
    }
}

namespace {
    use ResellerBytesAiOps\SignedApiClient;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SignedApiClient.php';

    function assertHeartbeatCondition(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    $GLOBALS['rb_mock_response_body'] = '{"data":{"accepted":true}}';
    $GLOBALS['rb_mock_http_status'] = 202;
    $GLOBALS['rb_mock_curl_exec_result'] = true;

    $result = (new SignedApiClient)->heartbeat([
        'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
        'secret' => str_repeat('b', 64),
    ], [
        'addon_version' => SignedApiClient::ADDON_VERSION,
        'whmcs_version' => '8.13.7',
        'cli_php_version' => '8.1.34',
        'sent_at' => '2026-11-22T01:00:00Z',
        'cron_kind' => 'daily',
    ]);

    assertHeartbeatCondition($result['http_status'] === 202, 'Heartbeat HTTP status was not preserved.');
    assertHeartbeatCondition($result['accepted'] === true, 'Heartbeat accepted flag was not preserved.');
    assertHeartbeatCondition(
        $GLOBALS['rb_mock_curl_url'] === 'https://ops.resellerbytes.com/api/v1/whmcs/heartbeats',
        'Heartbeat used an unexpected API destination.',
    );

    $body = $GLOBALS['rb_mock_curl_options'][CURLOPT_POSTFIELDS];
    $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    assertHeartbeatCondition($decoded['cron_kind'] === 'daily', 'Heartbeat cron_kind was not sent.');
    assertHeartbeatCondition($decoded['cli_php_version'] === '8.1.34', 'Heartbeat cli_php_version was not sent.');

    echo "WHMCS addon heartbeat tests passed.\n";
}

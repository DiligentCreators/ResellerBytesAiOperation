<?php

namespace ResellerBytesAiOps {
    use stdClass;

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

        $responseBody = $GLOBALS['rb_mock_response_body'] ?? '{"data":{"duplicate":false}}';
        $callback = $GLOBALS['rb_mock_curl_options'][CURLOPT_WRITEFUNCTION];

        return $callback($handle, $responseBody) === strlen($responseBody);
    }

    function curl_getinfo(object $handle, int $option): int
    {
        return $GLOBALS['rb_mock_http_status'] ?? 202;
    }

    function curl_close(object $handle): void {}
}

namespace {
    use ResellerBytesAiOps\SignedApiClient;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SignedApiClient.php';

    function assertSignedClientCondition(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \\RuntimeException($message);
        }
    }

    $integrationId = 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10';
    $secret = str_repeat('b', 64);
    $result = (new SignedApiClient)->submit([
        'integration_id' => $integrationId,
        'secret' => $secret,
    ], [
        'system' => [
            'whmcs_version' => '8.13.7-release.1',
        ],
    ]);

    assertSignedClientCondition($result['http_status'] === 202, 'The API response status was not preserved.');
    assertSignedClientCondition($result['duplicate'] === false, 'The API duplicate result was not preserved.');
    assertSignedClientCondition(
        $GLOBALS['rb_mock_curl_url'] === 'https://ops.resellerbytes.com/api/v1/whmcs/diagnostic-snapshots',
        'The client used an unexpected API destination.',
    );

    $options = $GLOBALS['rb_mock_curl_options'];
    $headers = [];

    foreach ($options[CURLOPT_HTTPHEADER] as $header) {
        [$name, $value] = explode(': ', $header, 2);
        $headers[$name] = $value;
    }

    $body = $options[CURLOPT_POSTFIELDS];
    $snapshot = json_decode($body, true, 8, JSON_THROW_ON_ERROR);

    assertSignedClientCondition((bool) preg_match('/\A[0-9]{10}\z/', $headers['X-RB-Timestamp']), 'Timestamp format is invalid.');
    assertSignedClientCondition((bool) preg_match('/\A[a-f0-9]{64}\z/', $headers['X-RB-Nonce']), 'Nonce format is invalid.');
    assertSignedClientCondition((bool) preg_match('/\A[a-f0-9]{64}\z/', $headers['X-RB-Signature']), 'Signature format is invalid.');
    assertSignedClientCondition($headers['X-RB-Integration'] === $integrationId, 'Integration identifier was not sent.');
    assertSignedClientCondition($snapshot['schema_version'] === '1.0', 'Snapshot schema version is incorrect.');
    assertSignedClientCondition($snapshot['payload']['system']['whmcs_version'] === '8.13.7-release.1', 'Diagnostic payload was not sent.');
    assertSignedClientCondition(strpos($body, $secret) === false, 'The HMAC secret was included in the request body.');

    $canonicalMessage = implode("\n", [
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        $headers['X-RB-Timestamp'],
        $headers['X-RB-Nonce'],
        hash('sha256', $body),
    ]);

    assertSignedClientCondition(
        hash_equals(hash_hmac('sha256', $canonicalMessage, $secret), $headers['X-RB-Signature']),
        'The HMAC signature does not match the canonical request.',
    );
    assertSignedClientCondition($options[CURLOPT_SSL_VERIFYPEER] === true, 'TLS peer verification is disabled.');
    assertSignedClientCondition($options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS hostname verification is disabled.');
    assertSignedClientCondition($options[CURLOPT_FOLLOWLOCATION] === false, 'HTTP redirects are enabled.');
    assertSignedClientCondition($options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'The client is not restricted to HTTPS.');

    echo "WHMCS addon signed API client tests passed.\n";
}

namespace {
    use ResellerBytesAiOps\SignedApiClient;

    $GLOBALS['rb_mock_response_body'] = str_repeat('x', 17000);

    try {
        (new SignedApiClient)->submit([
            'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
            'secret' => str_repeat('b', 64),
        ], [
            'system' => ['whmcs_version' => '8.13.7'],
        ]);

        throw new \\RuntimeException('Oversized API response was accepted.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'diagnostic_submission_failed') {
            throw $exception;
        }
    }
}

namespace {
    function assertSignedClientFailure(string $expectedMessage, string $label): void
    {
        try {
            (new \ResellerBytesAiOps\SignedApiClient)->submit([
                'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
                'secret' => str_repeat('b', 64),
            ], [
                'system' => ['whmcs_version' => '8.13.7'],
            ]);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === $expectedMessage) {
                return;
            }

            throw new \RuntimeException($label.' returned an unexpected error code.');
        }

        throw new \RuntimeException($label.' was unexpectedly accepted.');
    }

    $GLOBALS['rb_mock_response_body'] = '{"data":{"duplicate":false}}';
    $GLOBALS['rb_mock_http_status'] = 500;
    assertSignedClientFailure('diagnostic_submission_failed', 'HTTP 500 response');

    $GLOBALS['rb_mock_http_status'] = 202;
    $GLOBALS['rb_mock_response_body'] = 'not-json';
    assertSignedClientFailure('diagnostic_response_invalid', 'Malformed JSON response');

    $GLOBALS['rb_mock_response_body'] = '';
    assertSignedClientFailure('diagnostic_submission_failed', 'Empty API response');

    $GLOBALS['rb_mock_response_body'] = '{"data":{"duplicate":false}}';
    $GLOBALS['rb_mock_curl_exec_result'] = false;
    assertSignedClientFailure('diagnostic_submission_failed', 'cURL transport failure');

    echo "WHMCS addon signed API client failure-path tests passed.\n";
}

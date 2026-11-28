<?php

namespace {
    use ResellerBytesAiOps\ApiClientException;
    use ResellerBytesAiOps\FailureCode;
    use ResellerBytesAiOps\HttpTransport;
    use ResellerBytesAiOps\SignedApiClient;

    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/FailureCode.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/HttpTransport.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/CurlHttpTransport.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/ApiClientException.php';
    require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/SignedApiClient.php';

    function assertHttpFailure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param  array{executed?: bool, errno?: int, http_status?: int, body?: string, response_too_large?: bool}  $result
     */
    function fakeTransport(array $result): HttpTransport
    {
        return new class($result) implements HttpTransport
        {
            /** @param array{executed?: bool, errno?: int, http_status?: int, body?: string, response_too_large?: bool} $result */
            public function __construct(private array $result) {}

            public function execute(string $url, array $options, int $maxResponseBytes): array
            {
                return [
                    'executed' => (bool) ($this->result['executed'] ?? true),
                    'errno' => (int) ($this->result['errno'] ?? 0),
                    'http_status' => (int) ($this->result['http_status'] ?? 202),
                    'body' => (string) ($this->result['body'] ?? ''),
                    'response_too_large' => (bool) ($this->result['response_too_large'] ?? false),
                ];
            }
        };
    }

    function credentials(): array
    {
        return [
            'integration_id' => 'a3a5d8b4-51b1-4c27-8f61-8e6f7d4a9c10',
            'secret' => bin2hex(random_bytes(32)),
        ];
    }

    function expectCode(callable $callback, string $expected): void
    {
        try {
            $callback();
        } catch (RuntimeException $exception) {
            assertHttpFailure(
                $exception->getMessage() === $expected,
                'Expected '.$expected.' but got '.$exception->getMessage(),
            );
            assertHttpFailure(
                FailureCode::sanitize($exception->getMessage()) === $expected,
                'FailureCode::sanitize altered '.$expected,
            );

            return;
        }

        throw new RuntimeException('Expected failure code '.$expected.' was not thrown.');
    }

    $transportCases = [
        'http_dns_failed' => ['executed' => false, 'errno' => 6, 'http_status' => 0, 'body' => '', 'response_too_large' => false],
        'http_connect_failed' => ['executed' => false, 'errno' => 7, 'http_status' => 0, 'body' => '', 'response_too_large' => false],
        'http_timeout' => ['executed' => false, 'errno' => 28, 'http_status' => 0, 'body' => '', 'response_too_large' => false],
        'http_tls_failed' => ['executed' => false, 'errno' => 60, 'http_status' => 0, 'body' => '', 'response_too_large' => false],
        'http_transport_failed' => ['executed' => false, 'errno' => 55, 'http_status' => 0, 'body' => '', 'response_too_large' => false],
    ];

    foreach ($transportCases as $code => $result) {
        expectCode(
            static fn () => (new SignedApiClient(fakeTransport($result)))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]),
            $code,
        );
    }

    $statusCases = [
        400 => 'http_status_bad_request',
        401 => 'http_status_unauthenticated',
        403 => 'http_status_forbidden',
        404 => 'http_status_not_found',
        409 => 'http_status_conflict',
        422 => 'http_status_validation_failed',
        429 => 'http_status_rate_limited',
        500 => 'http_status_server_error',
        503 => 'http_status_server_error',
        418 => 'http_status_unexpected',
    ];

    foreach ($statusCases as $status => $code) {
        expectCode(
            static fn () => (new SignedApiClient(fakeTransport([
                'executed' => true,
                'errno' => 0,
                'http_status' => $status,
                'body' => '<html>error</html>',
                'response_too_large' => false,
            ])))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]),
            $code,
        );
    }

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 502,
            'body' => 'Bad Gateway',
            'response_too_large' => false,
        ])))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]),
        'http_status_server_error',
    );

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => false,
            'errno' => 23,
            'http_status' => 422,
            'body' => str_repeat('x', 100),
            'response_too_large' => true,
        ])))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]),
        'http_status_validation_failed',
    );

    $secretLookingMessage = 'The field /var/www/secret/credentials.json must be at least 1.';
    $fieldKey = 'payload.whmcs.module_queue.items.0.service_id';
    try {
        (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 422,
            'body' => json_encode([
                'message' => 'The given data was invalid.',
                'errors' => [
                    $fieldKey => [$secretLookingMessage],
                ],
            ], JSON_THROW_ON_ERROR),
            'response_too_large' => false,
        ])))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]);
        throw new RuntimeException('Expected 422 ApiClientException was not thrown.');
    } catch (ApiClientException $exception) {
        assertHttpFailure(
            $exception->getMessage() === 'http_status_validation_failed',
            '422 exception message must remain the letters-only failure code.',
        );
        assertHttpFailure(
            FailureCode::sanitize($exception->getMessage()) === 'http_status_validation_failed',
            'FailureCode::sanitize must keep http_status_validation_failed.',
        );
        assertHttpFailure(
            $exception->fieldKeys() === [$fieldKey],
            '422 field keys were not extracted from the errors object.',
        );
        assertHttpFailure(
            str_contains($exception->fieldsAlertSuffix(), 'Fields: '.$fieldKey),
            'Alert suffix must include sanitized field keys.',
        );
        assertHttpFailure(
            str_contains($exception->fieldsLogSuffix(), 'fields: '.$fieldKey),
            'Log suffix must include sanitized field keys.',
        );
        assertHttpFailure(
            ! str_contains($exception->getMessage(), $secretLookingMessage),
            'Exception message must never include validation message text.',
        );
        assertHttpFailure(
            ! str_contains($exception->fieldsAlertSuffix(), $secretLookingMessage)
            && ! str_contains($exception->fieldsLogSuffix(), $secretLookingMessage),
            'Field key suffixes must never include validation message text.',
        );
        assertHttpFailure(
            ! str_contains($exception->fieldsAlertSuffix(), '/var/www')
            && ! str_contains($exception->fieldsLogSuffix(), '/var/www'),
            'Field key suffixes must never include filesystem paths from error values.',
        );
    }

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => false,
            'errno' => 23,
            'http_status' => 202,
            'body' => str_repeat('x', 100),
            'response_too_large' => true,
        ])))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]),
        'http_response_too_large',
    );

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 202,
            'body' => '',
            'response_too_large' => false,
        ])))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]),
        'http_response_empty',
    );

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 202,
            'body' => 'not-json',
            'response_too_large' => false,
        ])))->request(credentials(), 'POST', '/api/v1/whmcs/diagnostic-snapshots', ['x' => 1]),
        'http_response_invalid',
    );

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 202,
            'body' => '{"data":{}}',
            'response_too_large' => false,
        ])))->submit(credentials(), ['system' => ['whmcs_version' => '8.13.7']]),
        'diagnostic_response_invalid',
    );

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 202,
            'body' => '{"data":{}}',
            'response_too_large' => false,
        ])))->heartbeat(credentials(), [
            'addon_version' => '1.2.1',
            'whmcs_version' => '8.13.7',
            'cli_php_version' => '8.1.0',
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'cron_kind' => 'system',
        ]),
        'heartbeat_response_invalid',
    );

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 200,
            'body' => '{"data":{}}',
            'response_too_large' => false,
        ])))->listPendingSafeActions(credentials()),
        'pending_actions_response_invalid',
    );

    expectCode(
        static fn () => (new SignedApiClient(fakeTransport([
            'executed' => true,
            'errno' => 0,
            'http_status' => 200,
            'body' => '{"data":{}}',
            'response_too_large' => false,
        ])))->reportSafeActionResult(credentials(), '00000000-0000-4000-8000-000000000001', [
            'status' => 'success',
            'summary' => 'ok',
            'evidence' => [],
        ]),
        'safe_action_result_response_invalid',
    );

    echo "WHMCS addon HTTP failure code tests passed.\n";
}

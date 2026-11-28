<?php

namespace ResellerBytesAiOps;

use JsonException;
use RuntimeException;

final class SignedApiClient
{
    private const BASE_URL = 'https://ops.resellerbytes.com';

    public const ADDON_VERSION = '1.2.3';

    private const MAX_RESPONSE_BYTES = 16384;

    /** @var list<int> */
    private const TLS_ERRNOS = [
        35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91,
    ];

    /** @var list<int> */
    private const CONNECT_ERRNOS = [
        7, 5, 45,
    ];

    private HttpTransport $transport;

    public function __construct(?HttpTransport $transport = null)
    {
        $this->transport = $transport ?? new CurlHttpTransport;
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @param  array<string, mixed>  $diagnostics
     * @return array{http_status: int, duplicate: bool}
     */
    public function submit(array $credentials, array $diagnostics): array
    {
        $snapshot = [
            'snapshot_uuid' => $this->uuidV4(),
            'schema_version' => '1.1',
            'addon_version' => self::ADDON_VERSION,
            'collected_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => $diagnostics,
        ];

        try {
            $response = $this->request($credentials, 'POST', '/api/v1/whmcs/diagnostic-snapshots', $snapshot);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'http_response_invalid') {
                throw new RuntimeException('diagnostic_response_invalid');
            }

            throw $exception;
        }

        if (! in_array($response['http_status'], [200, 202], true)
            || ! isset($response['json']['data']['duplicate'])
            || ! is_bool($response['json']['data']['duplicate'])) {
            throw new RuntimeException('diagnostic_response_invalid');
        }

        return [
            'http_status' => $response['http_status'],
            'duplicate' => $response['json']['data']['duplicate'],
        ];
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @param  array<string, mixed>  $body
     * @return array{http_status: int, accepted: bool, auto_collect_due: bool, pending_approved_actions: bool}
     */
    public function heartbeat(
        array $credentials,
        array $body,
        int $connectTimeout = 5,
        int $timeout = 30,
    ): array {
        try {
            $response = $this->request(
                $credentials,
                'POST',
                '/api/v1/whmcs/heartbeats',
                $body,
                $connectTimeout,
                $timeout,
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'http_response_invalid') {
                throw new RuntimeException('heartbeat_response_invalid');
            }

            throw $exception;
        }

        if (! in_array($response['http_status'], [200, 202], true)
            || ! isset($response['json']['data']['accepted'])
            || ! is_bool($response['json']['data']['accepted'])) {
            throw new RuntimeException('heartbeat_response_invalid');
        }

        return [
            'http_status' => $response['http_status'],
            'accepted' => $response['json']['data']['accepted'],
            'auto_collect_due' => (bool) ($response['json']['data']['auto_collect_due'] ?? false),
            'pending_approved_actions' => (bool) ($response['json']['data']['pending_approved_actions'] ?? false),
        ];
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @return array{actions: list<array<string, mixed>>, host_credentials_configured: bool}
     */
    public function listPendingSafeActions(array $credentials): array
    {
        try {
            $response = $this->request($credentials, 'GET', '/api/v1/whmcs/safe-actions/pending', null);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'http_response_invalid') {
                throw new RuntimeException('pending_actions_response_invalid');
            }

            throw $exception;
        }

        if ($response['http_status'] !== 200
            || ! isset($response['json']['data']['actions'])
            || ! is_array($response['json']['data']['actions'])) {
            throw new RuntimeException('pending_actions_response_invalid');
        }

        return [
            'actions' => array_values($response['json']['data']['actions']),
            'host_credentials_configured' => (bool) ($response['json']['data']['host_credentials_configured'] ?? false),
        ];
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @param  array<string, mixed>  $payload
     * @return array{id: string, status: string}
     */
    public function reportSafeActionResult(array $credentials, string $executionId, array $payload): array
    {
        if (! preg_match('/\A[0-9a-fA-F-]{36}\z/', $executionId)) {
            throw new RuntimeException('invalid_execution_id');
        }

        try {
            $response = $this->request(
                $credentials,
                'POST',
                '/api/v1/whmcs/safe-actions/'.$executionId.'/result',
                $payload,
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'http_response_invalid') {
                throw new RuntimeException('safe_action_result_response_invalid');
            }

            throw $exception;
        }

        if ($response['http_status'] !== 200
            || ! isset($response['json']['data']['id'], $response['json']['data']['status'])
            || ! is_string($response['json']['data']['id'])
            || ! is_string($response['json']['data']['status'])) {
            throw new RuntimeException('safe_action_result_response_invalid');
        }

        return [
            'id' => $response['json']['data']['id'],
            'status' => $response['json']['data']['status'],
        ];
    }

    /**
     * @param  array{integration_id: string, secret: string}  $credentials
     * @param  array<string, mixed>|null  $jsonBody
     * @return array{http_status: int, json: array<string, mixed>}
     */
    public function request(
        array $credentials,
        string $method,
        string $path,
        ?array $jsonBody,
        int $connectTimeout = 5,
        int $timeout = 30,
    ): array {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('curl_extension_unavailable');
        }

        $method = strtoupper($method);
        $body = '';

        if ($jsonBody !== null) {
            try {
                $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
                if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
                }
                $body = json_encode($jsonBody, $flags);
            } catch (JsonException $exception) {
                throw new RuntimeException('request_encoding_failed');
            }
        } elseif ($method === 'GET') {
            $body = '';
        } else {
            $body = '{}';
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(32));
        $canonicalMessage = implode("\n", [
            $method,
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        $signature = hash_hmac('sha256', $canonicalMessage, $credentials['secret']);
        $url = self::BASE_URL.$path;

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-RB-Integration: '.$credentials['integration_id'],
            'X-RB-Timestamp: '.$timestamp,
            'X-RB-Nonce: '.$nonce,
            'X-RB-Signature: '.$signature,
        ];

        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'ResellerBytes-AI-Ops-WHMCS/'.self::ADDON_VERSION,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
            if ($body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $result = $this->transport->execute($url, $options, self::MAX_RESPONSE_BYTES);
        $httpStatus = (int) $result['http_status'];
        $responseBody = (string) $result['body'];
        $responseTooLarge = (bool) $result['response_too_large'];

        if (! $result['executed'] && ! $responseTooLarge) {
            throw new RuntimeException($this->transportFailureCode((int) $result['errno']));
        }

        if ($httpStatus < 200 || $httpStatus > 299) {
            if ($httpStatus === 422) {
                throw $this->validationFailedException($responseBody);
            }

            throw new RuntimeException($this->httpStatusFailureCode($httpStatus));
        }

        if ($responseTooLarge) {
            throw new RuntimeException('http_response_too_large');
        }

        if ($responseBody === '') {
            throw new RuntimeException('http_response_empty');
        }

        try {
            $response = json_decode($responseBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('http_response_invalid');
        }

        if (! is_array($response)) {
            throw new RuntimeException('http_response_invalid');
        }

        return [
            'http_status' => $httpStatus,
            'json' => $response,
        ];
    }

    private function transportFailureCode(int $errno): string
    {
        if ($errno === 6) {
            return 'http_dns_failed';
        }

        if ($errno === 28) {
            return 'http_timeout';
        }

        if (in_array($errno, self::TLS_ERRNOS, true)) {
            return 'http_tls_failed';
        }

        if (in_array($errno, self::CONNECT_ERRNOS, true)) {
            return 'http_connect_failed';
        }

        return 'http_transport_failed';
    }

    private function httpStatusFailureCode(int $status): string
    {
        if ($status === 400) {
            return 'http_status_bad_request';
        }

        if ($status === 401) {
            return 'http_status_unauthenticated';
        }

        if ($status === 403) {
            return 'http_status_forbidden';
        }

        if ($status === 404) {
            return 'http_status_not_found';
        }

        if ($status === 409) {
            return 'http_status_conflict';
        }

        if ($status === 422) {
            return 'http_status_validation_failed';
        }

        if ($status === 429) {
            return 'http_status_rate_limited';
        }

        if ($status >= 500 && $status <= 599) {
            return 'http_status_server_error';
        }

        return 'http_status_unexpected';
    }

    private function validationFailedException(string $responseBody): ApiClientException
    {
        $keys = [];

        try {
            $decoded = json_decode($responseBody, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
                foreach (array_keys($decoded['errors']) as $key) {
                    if (! is_string($key)) {
                        continue;
                    }

                    if (preg_match('/\A[A-Za-z0-9._*-]{1,120}\z/', $key) !== 1) {
                        continue;
                    }

                    $keys[] = $key;
                    if (count($keys) >= 20) {
                        break;
                    }
                }
            }
        } catch (JsonException) {
            // Body may be HTML or truncated; still surface the status code.
        }

        return new ApiClientException('http_status_validation_failed', $keys);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}

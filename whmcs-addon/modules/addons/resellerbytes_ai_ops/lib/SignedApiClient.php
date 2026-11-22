<?php

namespace ResellerBytesAiOps;

use JsonException;
use RuntimeException;

final class SignedApiClient
{
    private const BASE_URL = 'https://ops.resellerbytes.com';

    public const ADDON_VERSION = '1.2.1';

    private const MAX_RESPONSE_BYTES = 16384;

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

            throw new RuntimeException('diagnostic_submission_failed');
        }

        if (! in_array($response['http_status'], [200, 202], true)
            || ! isset($response['json']['data']['duplicate'])
            || ! is_bool($response['json']['data']['duplicate'])) {
            throw new RuntimeException('diagnostic_submission_failed');
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

            throw new RuntimeException('heartbeat_submission_failed');
        }

        if (! in_array($response['http_status'], [200, 202], true)
            || ! isset($response['json']['data']['accepted'])
            || ! is_bool($response['json']['data']['accepted'])) {
            throw new RuntimeException('heartbeat_submission_failed');
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
        $response = $this->request($credentials, 'GET', '/api/v1/whmcs/safe-actions/pending', null);

        if ($response['http_status'] !== 200
            || ! isset($response['json']['data']['actions'])
            || ! is_array($response['json']['data']['actions'])) {
            throw new RuntimeException('pending_actions_fetch_failed');
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

        $response = $this->request(
            $credentials,
            'POST',
            '/api/v1/whmcs/safe-actions/'.$executionId.'/result',
            $payload,
        );

        if ($response['http_status'] !== 200
            || ! isset($response['json']['data']['id'], $response['json']['data']['status'])
            || ! is_string($response['json']['data']['id'])
            || ! is_string($response['json']['data']['status'])) {
            throw new RuntimeException('safe_action_result_failed');
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
                $body = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('http_client_initialization_failed');
        }

        $responseBody = '';
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
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }

                $responseBody .= $chunk;

                return strlen($chunk);
            },
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

        if (! curl_setopt_array($handle, $options)) {
            curl_close($handle);

            throw new RuntimeException('http_client_configuration_failed');
        }

        $executed = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($executed !== true || $responseBody === '') {
            throw new RuntimeException('http_request_failed');
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

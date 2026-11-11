<?php

namespace ResellerBytesAiOps;

use RuntimeException;

final class SignedApiClient
{
    private const ENDPOINT = 'https://ops.resellerbytes.com/api/v1/whmcs/diagnostic-snapshots';

    private const REQUEST_PATH = '/api/v1/whmcs/diagnostic-snapshots';

    private const ADDON_VERSION = '1.0.0';

    /**
     * @param array{integration_id: string, secret: string} $credentials
     * @param array<string, mixed> $diagnostics
     * @return array{http_status: int, duplicate: bool}
     */
    public function submit(array $credentials, array $diagnostics): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('curl_extension_unavailable');
        }

        $snapshot = [
            'snapshot_uuid' => $this->uuidV4(),
            'schema_version' => '1.0',
            'addon_version' => self::ADDON_VERSION,
            'collected_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => $diagnostics,
        ];

        try {
            $body = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new RuntimeException('snapshot_encoding_failed');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(32));
        $canonicalMessage = implode("\n", [
            'POST',
            self::REQUEST_PATH,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        $signature = hash_hmac('sha256', $canonicalMessage, $credentials['secret']);
        $handle = curl_init(self::ENDPOINT);

        if ($handle === false) {
            throw new RuntimeException('http_client_initialization_failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-RB-Integration: '.$credentials['integration_id'],
                'X-RB-Timestamp: '.$timestamp,
                'X-RB-Nonce: '.$nonce,
                'X-RB-Signature: '.$signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'ResellerBytes-AI-Ops-WHMCS/'.self::ADDON_VERSION,
        ]);

        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if (! is_string($responseBody) || ! in_array($httpStatus, [200, 202], true)) {
            throw new RuntimeException('diagnostic_submission_failed');
        }

        try {
            $response = json_decode($responseBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('diagnostic_response_invalid');
        }

        if (! is_array($response)
            || ! isset($response['data']['duplicate'])
            || ! is_bool($response['data']['duplicate'])) {
            throw new RuntimeException('diagnostic_response_invalid');
        }

        return [
            'http_status' => $httpStatus,
            'duplicate' => $response['data']['duplicate'],
        ];
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }
}

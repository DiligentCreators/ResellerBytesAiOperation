<?php

namespace App\Services\Whmcs;

use App\Models\WhmcsIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds signed WHMCS addon-compatible requests and dispatches them through the HTTP kernel.
 * Local smoke-test tooling only — never used for outbound production traffic.
 */
class LocalSignedWhmcsRequestDispatcher
{
    public const ADDON_VERSION = '1.2.4';

    /**
     * @param  array<string, mixed>  $jsonBody
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    public function post(WhmcsIntegration $integration, string $path, array $jsonBody): array
    {
        $body = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(32));
        $canonicalMessage = implode("\n", [
            'POST',
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
        $signature = hash_hmac('sha256', $canonicalMessage, $integration->secret);

        $request = Request::create(
            $path,
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_RB_INTEGRATION' => $integration->id,
                'HTTP_X_RB_TIMESTAMP' => $timestamp,
                'HTTP_X_RB_NONCE' => $nonce,
                'HTTP_X_RB_SIGNATURE' => $signature,
            ],
            $body,
        );

        /** @var Response $response */
        $response = app()->handle($request);
        $responseBody = $response->getContent();
        $decoded = null;

        if (is_string($responseBody) && $responseBody !== '') {
            try {
                $parsed = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                $decoded = is_array($parsed) ? $parsed : null;
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => is_string($responseBody) ? $responseBody : '',
            'json' => $decoded,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string, json: array<string, mixed>|null, snapshot: array<string, mixed>}
     */
    public function submitDiagnosticSnapshot(
        WhmcsIntegration $integration,
        array $payload,
        string $schemaVersion = '1.1',
        ?string $addonVersion = null,
    ): array {
        $snapshot = [
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => $schemaVersion,
            'addon_version' => $addonVersion ?? self::ADDON_VERSION,
            'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'payload' => $payload,
        ];

        $result = $this->post($integration, '/api/v1/whmcs/diagnostic-snapshots', $snapshot);
        $result['snapshot'] = $snapshot;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    public function submitHeartbeat(WhmcsIntegration $integration, array $overrides = []): array
    {
        $body = array_replace([
            'addon_version' => self::ADDON_VERSION,
            'whmcs_version' => '8.13.7',
            'cli_php_version' => '8.1.34',
            'sent_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'cron_kind' => 'system',
        ], $overrides);

        return $this->post($integration, '/api/v1/whmcs/heartbeats', $body);
    }
}

<?php

namespace App\Billing\Kelviq;

use App\Services\Billing\BillingSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class KelviqClient
{
    /**
     * Create or upsert a Kelviq customer. customerId is our stable external id.
     *
     * @param  array{customerId: string, name?: string, email?: string}  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(array $payload): array
    {
        // TODO(kelviq): confirm with Kelviq whether POST /customers/ is upsert-safe on duplicate customerId.
        return $this->request('POST', '/customers/', $payload, retry: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{checkoutUrl: string, checkoutSessionId: string}
     */
    public function createCheckout(array $payload): array
    {
        $response = $this->request('POST', '/checkout/', $payload, retry: false);
        $checkoutUrl = $response['checkoutUrl'] ?? null;
        $checkoutSessionId = $response['checkoutSessionId'] ?? null;

        if (! is_string($checkoutUrl) || $checkoutUrl === ''
            || ! is_string($checkoutSessionId) || $checkoutSessionId === '') {
            throw new RuntimeException('billing_kelviq_checkout_response_invalid');
        }

        return [
            'checkoutUrl' => $checkoutUrl,
            'checkoutSessionId' => $checkoutSessionId,
        ];
    }

    /**
     * Harmless documented read used by billing:kelviq:check and the settings test button.
     *
     * @return array<string, mixed>
     */
    public function listWebhookLogs(?int $pageSize = null): array
    {
        $query = $pageSize !== null ? ['page_size' => max(1, $pageSize)] : null;

        return $this->request('GET', '/webhook/logs/', $query, retry: true, asQuery: true);
    }

    public function baseUrl(): string
    {
        return app(BillingSettings::class)->kelviqBaseUrl();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload, bool $retry, bool $asQuery = false): array
    {
        $apiKey = app(BillingSettings::class)->kelviqApiKey();
        if ($apiKey === '') {
            throw new RuntimeException('billing_kelviq_api_key_missing');
        }

        $pending = $this->http($apiKey);
        if ($retry) {
            $pending = $pending->retry([100, 500, 1000], 0, function (\Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response->serverError() || $exception->response->status() === 429));
            });
        }

        $response = match (strtoupper($method)) {
            'GET' => $asQuery
                ? $pending->get($path, $payload ?? [])
                : $pending->get($path),
            'POST' => $pending->post($path, $payload ?? []),
            default => throw new RuntimeException('billing_kelviq_method_unsupported'),
        };

        if (! $response->successful()) {
            throw new RuntimeException('billing_kelviq_http_'.$response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [];
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function http(string $apiKey): PendingRequest
    {
        $timeout = max(1, (int) config('billing.kelviq.timeout', 15));
        $connectTimeout = max(1, (int) config('billing.kelviq.connect_timeout', 5));

        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->throw();
    }
}

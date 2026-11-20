<?php

namespace App\Billing\Sandbox;

use App\Contracts\Billing\BillingProviderAdapter;
use App\Data\Billing\VerifiedBillingWebhook;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Verifies HMAC-signed sandbox webhook envelopes produced by SandboxPaymentGateway.
 */
class SandboxBillingProviderAdapter implements BillingProviderAdapter
{
    public function provider(): string
    {
        return 'sandbox';
    }

    /**
     * @param  array<string, string|string[]>  $headers
     */
    public function verifyAndNormalize(string $rawBody, array $headers): VerifiedBillingWebhook
    {
        $signatureHeader = $headers['X-Sandbox-Signature'] ?? $headers['x-sandbox-signature'] ?? null;
        $timestampHeader = $headers['X-Sandbox-Timestamp'] ?? $headers['x-sandbox-timestamp'] ?? null;
        $signature = is_array($signatureHeader) ? ($signatureHeader[0] ?? null) : $signatureHeader;
        $timestamp = is_array($timestampHeader) ? ($timestampHeader[0] ?? null) : $timestampHeader;

        if (! is_string($signature) || ! is_string($timestamp) || ! ctype_digit($timestamp)) {
            throw new RuntimeException('billing_sandbox_signature_headers_missing');
        }

        $secret = (string) config('billing.sandbox.signing_secret');
        if ($secret === '') {
            throw new RuntimeException('billing_sandbox_signing_secret_missing');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('billing_sandbox_signature_invalid');
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('billing_sandbox_payload_invalid', previous: $exception);
        }

        $providerAccountId = $payload['provider_account_id'] ?? null;
        $providerEventId = $payload['provider_event_id'] ?? null;
        $eventType = $payload['event_type'] ?? null;
        $normalized = $payload['normalized'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;

        if (! is_string($providerAccountId) || ! is_string($providerEventId) || ! is_string($eventType)
            || ! is_array($normalized) || ! is_string($occurredAt)) {
            throw new InvalidArgumentException('billing_sandbox_payload_fields_invalid');
        }

        return new VerifiedBillingWebhook(
            $providerAccountId,
            $providerEventId,
            $eventType,
            $normalized,
            CarbonImmutable::createFromTimestampUTC((int) $timestamp),
            CarbonImmutable::parse($occurredAt)->utc(),
        );
    }
}

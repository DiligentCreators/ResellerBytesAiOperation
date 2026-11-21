<?php

namespace App\Billing\Kelviq;

use Carbon\CarbonImmutable;
use RuntimeException;

final class KelviqWebhookSignature
{
    /**
     * Verify Kelviq webhook headers per docs: HMAC-SHA256 over
     * `{webhook-id}.{webhook-timestamp}.{raw body}` with constant-time compare.
     *
     * @param  array<string, string|string[]>  $headers
     */
    public function assertValid(string $rawBody, array $headers, string $secret): int
    {
        if ($secret === '') {
            throw new RuntimeException('billing_kelviq_webhook_secret_missing');
        }

        $webhookId = $this->header($headers, 'webhook-id');
        $timestamp = $this->header($headers, 'webhook-timestamp');
        $signatureHeader = $this->header($headers, 'webhook-signature');

        if ($webhookId === null || $timestamp === null || $signatureHeader === null || ! ctype_digit($timestamp)) {
            throw new RuntimeException('billing_kelviq_signature_headers_missing');
        }

        $timestampInt = (int) $timestamp;
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        if (abs($now - $timestampInt) > 300) {
            throw new RuntimeException('billing_kelviq_signature_timestamp_stale');
        }

        $received = $this->extractV1Signature($signatureHeader);
        $expected = hash_hmac('sha256', $webhookId.'.'.$timestamp.'.'.$rawBody, $secret);

        if (! hash_equals($expected, $received)) {
            throw new RuntimeException('billing_kelviq_signature_invalid');
        }

        return $timestampInt;
    }

    private function extractV1Signature(string $header): string
    {
        $parts = explode(',', $header, 2);
        if (count($parts) !== 2 || $parts[0] !== 'v1' || $parts[1] === '' || ! ctype_xdigit($parts[1])) {
            throw new RuntimeException('billing_kelviq_signature_malformed');
        }

        return strtolower($parts[1]);
    }

    /**
     * @param  array<string, string|string[]>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            $resolved = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($resolved) && $resolved !== '' ? $resolved : null;
        }

        return null;
    }
}

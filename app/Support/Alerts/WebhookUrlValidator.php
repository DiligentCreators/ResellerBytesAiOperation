<?php

namespace App\Support\Alerts;

use Illuminate\Support\Str;

/**
 * Validates outbound webhook targets to reduce SSRF risk.
 * Production (and any non-local environment) rejects private/reserved hosts.
 */
final class WebhookUrlValidator
{
    /**
     * @return array{valid: bool, message: string|null}
     */
    public function validate(string $url, ?bool $allowPrivate = null): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['valid' => false, 'message' => 'Webhook URL is required.'];
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['valid' => false, 'message' => 'Webhook URL is not a valid URL.'];
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return ['valid' => false, 'message' => 'Webhook URL could not be parsed.'];
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return ['valid' => false, 'message' => 'Webhook URL must use HTTPS.'];
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return ['valid' => false, 'message' => 'Webhook URL must include a host.'];
        }

        $allowPrivate ??= ! app()->environment('production');

        if (! $allowPrivate && $this->isBlockedHost($host)) {
            return ['valid' => false, 'message' => 'Webhook URL must not target private or local addresses.'];
        }

        // Always block obvious loopback/metadata labels even outside production
        // so tests can assert SSRF protection without forcing APP_ENV=production.
        if ($this->isAlwaysBlockedHost($host)) {
            return ['valid' => false, 'message' => 'Webhook URL must not target private or local addresses.'];
        }

        return ['valid' => true, 'message' => null];
    }

    public function isValid(string $url, ?bool $allowPrivate = null): bool
    {
        return $this->validate($url, $allowPrivate)['valid'];
    }

    private function isAlwaysBlockedHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal'], true)) {
            return true;
        }

        if (Str::endsWith($host, '.localhost') || Str::endsWith($host, '.local')) {
            return true;
        }

        return $this->isPrivateIp($host);
    }

    private function isBlockedHost(string $host): bool
    {
        return $this->isAlwaysBlockedHost($host);
    }

    private function isPrivateIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Resolve hostname to IPs when possible; failure is not automatically private.
            $resolved = @gethostbynamel($host);
            if (! is_array($resolved) || $resolved === []) {
                return false;
            }

            foreach ($resolved as $ip) {
                if ($this->ipIsPrivateOrReserved($ip)) {
                    return true;
                }
            }

            return false;
        }

        return $this->ipIsPrivateOrReserved($host);
    }

    private function ipIsPrivateOrReserved(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}

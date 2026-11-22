<?php

namespace App\Support\Alerts;

use App\Support\Http\HostDnsResolver;
use Illuminate\Support\Str;

/**
 * Validates outbound webhook targets to reduce SSRF risk.
 *
 * Creation-time validation rejects blocked IP literals and hostnames that
 * currently resolve to blocked addresses. Unresolvable hostnames remain allowed
 * at creation (unless the host is an IP-like literal). Send-time preparation
 * re-resolves DNS, refuses any blocked address, disables redirects, and pins
 * the request to the checked addresses via CURLOPT_RESOLVE.
 */
final class WebhookUrlValidator
{
    private const PUBLIC_TEST_MESSAGE = 'Webhook URL must not target private or local addresses.';

    public function __construct(private readonly HostDnsResolver $dns) {}

    /**
     * @return array{valid: bool, message: string|null}
     */
    public function validate(string $url, ?bool $allowPrivate = null): array
    {
        return $this->evaluate($url, requireResolved: false, allowPrivate: $allowPrivate)['result'];
    }

    public function isValid(string $url, ?bool $allowPrivate = null): bool
    {
        return $this->validate($url, $allowPrivate)['valid'];
    }

    /**
     * Send-time guard: re-resolve, block unsafe targets, and return HTTP client options
     * that pin DNS and refuse redirects.
     *
     * @return array{
     *     valid: bool,
     *     message: string|null,
     *     request_options: array<string, mixed>
     * }
     */
    public function prepareOutbound(string $url, ?bool $allowPrivate = null): array
    {
        $evaluated = $this->evaluate($url, requireResolved: true, allowPrivate: $allowPrivate);
        $result = $evaluated['result'];

        if (! $result['valid']) {
            return [
                'valid' => false,
                'message' => $result['message'],
                'request_options' => [
                    'allow_redirects' => false,
                ],
            ];
        }

        $host = $evaluated['host'];
        $port = $evaluated['port'];
        $ips = $evaluated['ips'];
        $resolve = [];

        if ($host !== '' && $ips !== [] && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $addresses = [];
            foreach ($ips as $ip) {
                $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
            }
            $resolve[] = $host.':'.$port.':'.implode(',', $addresses);
        }

        $options = [
            'allow_redirects' => false,
        ];

        if ($resolve !== []) {
            $options['curl'] = [
                CURLOPT_RESOLVE => $resolve,
            ];
        }

        return [
            'valid' => true,
            'message' => null,
            'request_options' => $options,
        ];
    }

    /**
     * @return array{
     *     result: array{valid: bool, message: string|null},
     *     host: string,
     *     port: int,
     *     ips: list<string>
     * }
     */
    private function evaluate(string $url, bool $requireResolved, ?bool $allowPrivate): array
    {
        $empty = static fn (string $message): array => [
            'result' => ['valid' => false, 'message' => $message],
            'host' => '',
            'port' => 443,
            'ips' => [],
        ];

        $url = trim($url);
        if ($url === '') {
            return $empty('Webhook URL is required.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $empty('Webhook URL is not a valid URL.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $empty('Webhook URL could not be parsed.');
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return $empty('Webhook URL must use HTTPS.');
        }

        $rawHost = (string) ($parts['host'] ?? '');
        $host = $this->normalizeHost($rawHost);
        if ($host === '') {
            return $empty('Webhook URL must include a host.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            return $empty('Webhook URL must include a valid port.');
        }

        // allowPrivate is retained for call-site compatibility; SSRF ranges are always enforced.
        unset($allowPrivate);

        if ($this->isBlockedHostname($host)) {
            return $empty(self::PUBLIC_TEST_MESSAGE);
        }

        $literalIp = $this->parseIpLiteral($host);
        if ($literalIp !== null) {
            if ($this->ipIsBlocked($literalIp)) {
                return $empty(self::PUBLIC_TEST_MESSAGE);
            }

            return [
                'result' => ['valid' => true, 'message' => null],
                'host' => $host,
                'port' => $port,
                'ips' => [$literalIp],
            ];
        }

        if ($this->looksLikeIpLiteral($host)) {
            return $empty(self::PUBLIC_TEST_MESSAGE);
        }

        $resolved = $this->dns->resolve($host);
        if ($resolved === []) {
            if ($requireResolved) {
                return $empty(self::PUBLIC_TEST_MESSAGE);
            }

            return [
                'result' => ['valid' => true, 'message' => null],
                'host' => $host,
                'port' => $port,
                'ips' => [],
            ];
        }

        foreach ($resolved as $ip) {
            if ($this->ipIsBlocked($ip)) {
                return $empty(self::PUBLIC_TEST_MESSAGE);
            }
        }

        return [
            'result' => ['valid' => true, 'message' => null],
            'host' => $host,
            'port' => $port,
            'ips' => array_values($resolved),
        ];
    }

    private function normalizeHost(string $host): string
    {
        $host = Str::lower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return rtrim($host, '.');
    }

    private function isBlockedHostname(string $host): bool
    {
        if (in_array($host, ['localhost', 'metadata', 'metadata.google.internal'], true)) {
            return true;
        }

        return Str::endsWith($host, '.localhost')
            || Str::endsWith($host, '.local')
            || Str::endsWith($host, '.internal');
    }

    private function looksLikeIpLiteral(string $host): bool
    {
        if (str_contains($host, ':')) {
            return true;
        }

        if (preg_match('/^(?:0x[0-9a-f]+|0[0-7]+|[0-9]+)$/i', $host) === 1) {
            return true;
        }

        return preg_match('/^[0-9a-fx.]+$/i', $host) === 1 && str_contains($host, '.');
    }

    private function parseIpLiteral(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $ipv4 = $this->parseAlternateIpv4($host);
        if ($ipv4 !== null) {
            return $ipv4;
        }

        return null;
    }

    private function parseAlternateIpv4(string $host): ?string
    {
        if (preg_match('/^(?:0x[0-9a-f]+|0[0-7]+|[0-9]+)$/i', $host) === 1) {
            $value = $this->parseIpv4Part($host);
            if ($value === null || $value < 0 || $value > 0xFFFFFFFF) {
                return null;
            }

            return long2ip($value);
        }

        if (! str_contains($host, '.') || preg_match('/^[0-9a-fx.]+$/i', $host) !== 1) {
            return null;
        }

        $parts = explode('.', $host);
        $count = count($parts);
        if ($count < 1 || $count > 4) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $part) {
            $value = $this->parseIpv4Part($part);
            if ($value === null) {
                return null;
            }
            $numbers[] = $value;
        }

        if ($count === 1) {
            return $numbers[0] <= 0xFFFFFFFF ? long2ip($numbers[0]) : null;
        }

        if ($count === 2) {
            return ($numbers[0] <= 0xFF && $numbers[1] <= 0xFFFFFF)
                ? long2ip(($numbers[0] << 24) | $numbers[1])
                : null;
        }

        if ($count === 3) {
            return ($numbers[0] <= 0xFF && $numbers[1] <= 0xFF && $numbers[2] <= 0xFFFF)
                ? long2ip(($numbers[0] << 24) | ($numbers[1] << 16) | $numbers[2])
                : null;
        }

        return ($numbers[0] <= 0xFF && $numbers[1] <= 0xFF && $numbers[2] <= 0xFF && $numbers[3] <= 0xFF)
            ? long2ip(($numbers[0] << 24) | ($numbers[1] << 16) | ($numbers[2] << 8) | $numbers[3])
            : null;
    }

    private function parseIpv4Part(string $part): ?int
    {
        if ($part === '') {
            return null;
        }

        if (str_starts_with(Str::lower($part), '0x')) {
            if (preg_match('/^0x[0-9a-f]+$/i', $part) !== 1) {
                return null;
            }
            $value = hexdec(substr($part, 2));

            return is_int($value) ? $value : null;
        }

        if (preg_match('/^0[0-7]+$/', $part) === 1) {
            return (int) octdec($part);
        }

        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $part) !== 1) {
            return null;
        }

        return (int) $part;
    }

    private function ipIsBlocked(string $ip): bool
    {
        $ip = $this->normalizeHost($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->ipv4IsBlocked($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->ipv6IsBlocked($ip);
        }

        return true;
    }

    private function ipv4IsBlocked(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        // Unsigned 32-bit
        $long = $long & 0xFFFFFFFF;

        $ranges = [
            [0x00000000, 0xFF000000], // 0.0.0.0/8
            [0x0A000000, 0xFF000000], // 10.0.0.0/8
            [0x64400000, 0xFFC00000], // 100.64.0.0/10 CGNAT
            [0x7F000000, 0xFF000000], // 127.0.0.0/8
            [0xA9FE0000, 0xFFFF0000], // 169.254.0.0/16
            [0xAC100000, 0xFFF00000], // 172.16.0.0/12
            [0xC0A80000, 0xFFFF0000], // 192.168.0.0/16
            [0xE0000000, 0xF0000000], // 224.0.0.0/4 multicast
            [0xF0000000, 0xF0000000], // 240.0.0.0/4 reserved
        ];

        foreach ($ranges as [$base, $mask]) {
            if (($long & $mask) === $base) {
                return true;
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function ipv6IsBlocked(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false || strlen($binary) !== 16) {
            return true;
        }

        // Unspecified ::
        if ($binary === str_repeat("\x00", 16)) {
            return true;
        }

        // Loopback ::1
        if ($binary === str_repeat("\x00", 15)."\x01") {
            return true;
        }

        $first = ord($binary[0]);
        $second = ord($binary[1]);

        // Multicast ff00::/8
        if ($first === 0xFF) {
            return true;
        }

        // Link-local fe80::/10
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return true;
        }

        // Unique local fc00::/7
        if (($first & 0xFE) === 0xFC) {
            return true;
        }

        $embedded = $this->embeddedIpv4($binary);
        if ($embedded !== null) {
            // IPv4-mapped / compatible / NAT64 / 6to4: decide solely on the embedded v4.
            // PHP's FILTER_FLAG_NO_RES_RANGE rejects all ::ffff: addresses as reserved.
            return $this->ipv4IsBlocked($embedded);
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function embeddedIpv4(string $binary): ?string
    {
        // IPv4-mapped ::ffff:0:0/96
        if (substr($binary, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return inet_ntop(substr($binary, 12)) ?: null;
        }

        // IPv4-compatible ::/96 (excluding :: and ::1 already handled)
        if (substr($binary, 0, 12) === str_repeat("\x00", 12)) {
            $v4 = inet_ntop(substr($binary, 12));

            return is_string($v4) ? $v4 : null;
        }

        // NAT64 64:ff9b::/96
        if (substr($binary, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00") {
            return inet_ntop(substr($binary, 12)) ?: null;
        }

        // 6to4 2002::/16 embeds IPv4 in bytes 2-5
        if (substr($binary, 0, 2) === "\x20\x02") {
            return inet_ntop(substr($binary, 2, 4)) ?: null;
        }

        return null;
    }
}

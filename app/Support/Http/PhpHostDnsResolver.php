<?php

namespace App\Support\Http;

/**
 * System DNS resolver used for outbound URL SSRF checks.
 */
final class PhpHostDnsResolver implements HostDnsResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $ips = [];

        $aRecords = @dns_get_record($host, DNS_A);
        if (is_array($aRecords)) {
            foreach ($aRecords as $record) {
                $ip = $record['ip'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                $ip = $record['ipv6'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        // Fallback for environments where dns_get_record is unavailable for A.
        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                foreach ($fallback as $ip) {
                    if ($ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }
}

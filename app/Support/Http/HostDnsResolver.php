<?php

namespace App\Support\Http;

/**
 * Resolves a hostname to A/AAAA addresses for outbound URL safety checks.
 */
interface HostDnsResolver
{
    /**
     * @return list<string> IPv4/IPv6 literals (no brackets); empty when the name does not resolve.
     */
    public function resolve(string $host): array;
}

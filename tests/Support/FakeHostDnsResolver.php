<?php

namespace Tests\Support;

use App\Support\Http\HostDnsResolver;

/**
 * Map-backed DNS resolver for SSRF tests.
 */
final class FakeHostDnsResolver implements HostDnsResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(private array $records = []) {}

    /**
     * @param  list<string>  $ips
     */
    public function set(string $host, array $ips): void
    {
        $this->records[strtolower(rtrim($host, '.'))] = $ips;
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));

        return $this->records[$host] ?? [];
    }
}

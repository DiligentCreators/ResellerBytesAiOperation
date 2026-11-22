<?php

namespace Tests;

use App\Support\Http\HostDnsResolver;
use App\Support\Http\PhpHostDnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.sandbox.signing_secret' => Str::random(64),
            'operations.demo_accounts.password' => Str::random(32),
        ]);

        // Alert delivery tests use *.example.test hosts that do not resolve on CI.
        // Map the reserved .test TLD to a documentation public address so send-time
        // DNS pinning can run without reaching the network.
        $this->app->bind(HostDnsResolver::class, function (): HostDnsResolver {
            $inner = new PhpHostDnsResolver;

            return new class($inner) implements HostDnsResolver
            {
                public function __construct(private readonly HostDnsResolver $inner) {}

                public function resolve(string $host): array
                {
                    $normalized = strtolower(rtrim($host, '.'));
                    if (str_ends_with($normalized, '.test')) {
                        return ['203.0.113.10'];
                    }

                    return $this->inner->resolve($host);
                }
            };
        });
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

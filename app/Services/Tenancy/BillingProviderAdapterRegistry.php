<?php

namespace App\\Services\\Tenancy;

use App\\Contracts\\Billing\\BillingProviderAdapter;
use InvalidArgumentException;
use RuntimeException;

class BillingProviderAdapterRegistry
{
    public function resolve(string $provider): BillingProviderAdapter
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)) {
            throw new InvalidArgumentException('billing_provider_identifier_invalid');
        }

        $adapterClass = config('billing.providers.'.$provider);
        if (! is_string($adapterClass) || $adapterClass === '') {
            throw new RuntimeException('billing_provider_adapter_not_configured');
        }

        $adapter = app($adapterClass);
        if (! $adapter instanceof BillingProviderAdapter || ! hash_equals($provider, $adapter->provider())) {
            throw new RuntimeException('billing_provider_adapter_configuration_invalid');
        }

        return $adapter;
    }
}

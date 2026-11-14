<?php

namespace App\\Services\\Tenancy;

use App\\Models\\TenantBillingProviderAccount;
use RuntimeException;

class TenantBillingProviderAccountResolver
{
    public function resolve(string $provider, string $providerAccountId): string
    {
        return app(TenantBillingOperationsConnection::class)->run(function () use ($provider, $providerAccountId): string {
            $mapping = TenantBillingProviderAccount::query()
                ->where('provider', $provider)
                ->where('provider_account_id', $providerAccountId)
                ->where('status', 'active')
                ->whereNotNull('verified_at')
                ->first();

            if ($mapping === null) {
                throw new RuntimeException('billing_provider_account_unmapped');
            }

            return (string) $mapping->tenant_id;
        });
    }
}

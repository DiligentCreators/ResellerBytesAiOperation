<?php

namespace App\\Services\\Tenancy;

use App\\Models\\Tenant;
use App\\Models\\TenantBillingProviderAccount;
use App\\Models\\User;
use App\\Services\\Audit\\AuditEventWriter;
use Carbon\\CarbonImmutable;
use Illuminate\\Database\\QueryException;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;
use RuntimeException;

class TenantBillingProviderAccountService
{
    public function registerVerifiedAccount(string $tenantId, string $provider, string $providerAccountId, int $actorUserId): TenantBillingProviderAccount
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $provider)
            || trim($providerAccountId) === '' || strlen($providerAccountId) > 160) {
            throw new InvalidArgumentException('billing_provider_account_input_invalid');
        }

        return app(TenantDatabaseContext::class)->run($tenantId, $actorUserId, fn (): TenantBillingProviderAccount => DB::transaction(function () use ($tenantId, $provider, $providerAccountId, $actorUserId): TenantBillingProviderAccount {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actorUserId);
            app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');

            $mapping = TenantBillingProviderAccount::query()
                ->where('provider', $provider)
                ->where('provider_account_id', $providerAccountId)
                ->lockForUpdate()
                ->first();

            if ($mapping !== null && $mapping->tenant_id !== $tenantId) {
                throw new RuntimeException('billing_provider_account_already_bound');
            }

            $verifiedAt = CarbonImmutable::now('UTC');
            if ($mapping === null) {
                try {
                    $mapping = TenantBillingProviderAccount::query()->create([
                        'tenant_id' => $tenantId,
                        'provider' => $provider,
                        'provider_account_id' => $providerAccountId,
                        'status' => 'active',
                        'verified_at' => $verifiedAt,
                        'created_by_user_id' => $actorUserId,
                    ]);
                } catch (QueryException $exception) {
                    throw new RuntimeException('billing_provider_account_already_bound', previous: $exception);
                }
            } else {
                $mapping->forceFill(['status' => 'active', 'verified_at' => $verifiedAt])->save();
            }

            app(AuditEventWriter::class)->append([
                'actor_type' => 'user',
                'actor_id' => (string) $actorUserId,
                'tenant_id' => $tenantId,
                'event_type' => 'tenant_billing.provider_account_verified',
                'subject_type' => 'tenant_billing_provider_account',
                'subject_id' => (string) $mapping->id,
                'metadata' => [
                    'provider' => $provider,
                    'provider_account_id' => $providerAccountId,
                    'status' => 'active',
                ],
                'occurred_at' => $verifiedAt,
            ]);

            return $mapping->refresh();
        }, 3));
    }

    public function deactivateAccount(string $tenantId, int $mappingId, int $actorUserId): TenantBillingProviderAccount
    {
        return app(TenantDatabaseContext::class)->run($tenantId, $actorUserId, fn (): TenantBillingProviderAccount => DB::transaction(function () use ($tenantId, $mappingId, $actorUserId): TenantBillingProviderAccount {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $actor = User::query()->findOrFail($actorUserId);
            app(TenantRoleAuthorization::class)->require($actor, $tenantId, 'billing.manage');

            $mapping = TenantBillingProviderAccount::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($mappingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($mapping->status !== 'inactive') {
                $mapping->forceFill(['status' => 'inactive'])->save();

                app(AuditEventWriter::class)->append([
                    'actor_type' => 'user',
                    'actor_id' => (string) $actorUserId,
                    'tenant_id' => $tenantId,
                    'event_type' => 'tenant_billing.provider_account_deactivated',
                    'subject_type' => 'tenant_billing_provider_account',
                    'subject_id' => (string) $mapping->id,
                    'metadata' => [
                        'provider' => $mapping->provider,
                        'provider_account_id' => $mapping->provider_account_id,
                        'status' => 'inactive',
                    ],
                    'occurred_at' => now('UTC'),
                ]);
            }

            return $mapping->refresh();
        }, 3));
    }
}

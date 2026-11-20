<?php

namespace App\Services\Whmcs;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use App\Services\Tenancy\TenantDatabaseContext;
use App\Services\Tenancy\TenantResourceLimitService;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WhmcsIntegrationProvisioner
{
    /**
     * @return array{integration: WhmcsIntegration, plainTextSecret: string}
     */
    public function create(
        User $owner,
        string $name,
        ?string $tenantId = null,
        string $actorType = 'system_operator',
        ?string $actorId = null,
    ): array {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('The integration name must contain between 1 and 120 characters.');
        }

        if ($owner->email_verified_at === null || $owner->two_factor_confirmed_at === null || $owner->two_factor_secret === null) {
            throw new InvalidArgumentException('The owner must be an existing account with verified email and confirmed TOTP MFA.');
        }

        $tenantId = $tenantId !== null ? trim($tenantId) : null;
        if ($tenantId === '') {
            $tenantId = null;
        }

        $secret = bin2hex(random_bytes(32));

        $provision = function () use ($name, $secret, $owner, $tenantId, $actorType, $actorId): WhmcsIntegration {
            return DB::transaction(function () use ($name, $secret, $owner, $tenantId, $actorType, $actorId): WhmcsIntegration {
                if ($tenantId !== null) {
                    Tenant::query()
                        ->whereKey($tenantId)
                        ->where('status', Tenant::STATUS_ACTIVE)
                        ->lockForUpdate()
                        ->firstOrFail();

                    app(TenantRoleAuthorization::class)->require($owner, $tenantId, 'integrations.manage');
                    app(TenantResourceLimitService::class)->requireIntegrationCapacity($tenantId);
                }

                $integration = WhmcsIntegration::create([
                    'owner_user_id' => $owner->getKey(),
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'status' => 'active',
                    'secret' => $secret,
                    'secret_version' => 1,
                ]);

                app(AuditEventWriter::class)->append([
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'event_type' => 'whmcs_integration.created',
                    'subject_type' => 'whmcs_integration',
                    'subject_id' => $integration->id,
                    'tenant_id' => $tenantId,
                    'metadata' => [
                        'owner_user_id' => (string) $owner->getKey(),
                        'secret_version' => 1,
                        'tenant_id' => $tenantId,
                    ],
                    'occurred_at' => now(),
                ]);

                return $integration;
            });
        };

        try {
            $integration = $tenantId !== null
                ? app(TenantDatabaseContext::class)->run($tenantId, (int) $owner->getKey(), $provision)
                : $provision();
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        }

        return [
            'integration' => $integration,
            'plainTextSecret' => $secret,
        ];
    }
}

<?php

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TenantDatabaseContext
{
    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function run(?string $tenantId, ?int $userId, Closure $callback, ?string $integrationLookupId = null): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        foreach ([$tenantId, $integrationLookupId] as $uuid) {
            if ($uuid !== null && ! Str::isUuid($uuid)) {
                throw new \InvalidArgumentException('Invalid tenant database context UUID.');
            }
        }

        if ($userId !== null && $userId < 1) {
            throw new \InvalidArgumentException('Invalid tenant database context user ID.');
        }

        $previousTenantId = (string) ($connection->selectOne("select current_setting('app.current_tenant_id', true) as value", [], false)->value ?? '');
        $previousUserId = (string) ($connection->selectOne("select current_setting('app.current_user_id', true) as value", [], false)->value ?? '');
        $previousIntegrationLookupId = (string) ($connection->selectOne("select current_setting('app.integration_lookup_id', true) as value", [], false)->value ?? '');

        try {
            $connection->statement("select set_config('app.current_tenant_id', ?, false)", [$tenantId ?? '']);
            $connection->statement("select set_config('app.current_user_id', ?, false)", [$userId === null ? '' : (string) $userId]);
            $connection->statement("select set_config('app.integration_lookup_id', ?, false)", [$integrationLookupId ?? '']);

            return $callback();
        } finally {
            try {
                $connection->statement("select set_config('app.current_tenant_id', ?, false)", [$previousTenantId]);
                $connection->statement("select set_config('app.current_user_id', ?, false)", [$previousUserId]);
                $connection->statement("select set_config('app.integration_lookup_id', ?, false)", [$previousIntegrationLookupId]);
            } catch (Throwable) {
                $connection->disconnect();
            }
        }
    }
}

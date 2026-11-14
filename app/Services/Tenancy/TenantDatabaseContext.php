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

        $connection->selectOne("select set_config('app.current_tenant_id', ?, false)", [$tenantId ?? '']);
        $connection->selectOne("select set_config('app.current_user_id', ?, false)", [$userId === null ? '' : (string) $userId]);
        $connection->selectOne("select set_config('app.integration_lookup_id', ?, false)", [$integrationLookupId ?? '']);

        try {
            return $callback();
        } finally {
            try {
                $connection->selectOne("select set_config('app.current_tenant_id', '', false)");
                $connection->selectOne("select set_config('app.current_user_id', '', false)");
                $connection->selectOne("select set_config('app.integration_lookup_id', '', false)");
            } catch (Throwable) {
                $connection->disconnect();
            }
        }
    }
}

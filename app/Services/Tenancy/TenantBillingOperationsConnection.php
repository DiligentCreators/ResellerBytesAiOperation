<?php

namespace App\\Services\\Tenancy;

use Closure;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

class TenantBillingOperationsConnection
{
    /**
     * Run global billing reconciliation under the separately provisioned
     * platform operations role. The ordinary PostgreSQL runtime role remains
     * subject to tenant RLS and must not receive cross-tenant bypass rights.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $default = DB::getDefaultConnection();
        if (DB::connection($default)->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $username = config('database.connections.pgsql_operations.username');
        if (! is_string($username) || trim($username) === '') {
            throw new RuntimeException('billing_operations_database_role_not_configured');
        }

        DB::setDefaultConnection('pgsql_operations');

        try {
            DB::connection('pgsql_operations')->getPdo();

            return $callback();
        } finally {
            DB::setDefaultConnection($default);
            DB::disconnect('pgsql_operations');
        }
    }
}

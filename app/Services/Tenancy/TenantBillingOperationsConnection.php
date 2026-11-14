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

        try {
            $operations = DB::connection('pgsql_operations');
            if ($operations->getDriverName() !== 'pgsql') {
                throw new RuntimeException('billing_operations_database_driver_invalid');
            }

            $runtimeDatabase = DB::connection($default)->selectOne('select current_database() as database_name')->database_name;
            $operationsDatabase = $operations->selectOne('select current_database() as database_name')->database_name;
            if (! is_string($runtimeDatabase) || ! is_string($operationsDatabase)
                || ! hash_equals($runtimeDatabase, $operationsDatabase)) {
                throw new RuntimeException('billing_operations_database_target_mismatch');
            }

            DB::setDefaultConnection('pgsql_operations');

            return $callback();
        } finally {
            DB::setDefaultConnection($default);
            DB::disconnect('pgsql_operations');
        }
    }
}

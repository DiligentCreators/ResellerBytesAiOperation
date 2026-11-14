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

            $runtimeRole = DB::connection($default)->selectOne('SELECT current_user AS role_name');
            $runtimeRoleFlags = DB::connection($default)->selectOne('SELECT rolbypassrls, rolsuper FROM pg_roles WHERE rolname = current_user');
            $operationsRole = $operations->selectOne('SELECT current_user AS role_name');
            $role = $operations->selectOne('SELECT rolbypassrls, rolsuper FROM pg_roles WHERE rolname = current_user');
            if ($runtimeRole === null || $operationsRole === null || $runtimeRole->role_name === $operationsRole->role_name
                || $runtimeRoleFlags === null || filter_var($runtimeRoleFlags->rolbypassrls, FILTER_VALIDATE_BOOLEAN)
                || filter_var($runtimeRoleFlags->rolsuper, FILTER_VALIDATE_BOOLEAN)
                || $role === null || ! filter_var($role->rolbypassrls, FILTER_VALIDATE_BOOLEAN)
                || filter_var($role->rolsuper, FILTER_VALIDATE_BOOLEAN)) {
                throw new RuntimeException('billing_operations_database_role_privileges_invalid');
            }

            $ownedTables = $operations->selectOne("SELECT COUNT(*) AS owned_count FROM pg_class WHERE relname IN ('tenant_billing_outbox', 'tenant_billing_webhook_events', 'tenant_billing_provider_accounts', 'tenant_billing_adjustments', 'tenant_billing_invoices', 'tenant_billing_payments', 'tenant_subscriptions', 'audit_events') AND pg_get_userbyid(relowner) = current_user");
            if ((int) ($ownedTables->owned_count ?? 0) !== 0) {
                throw new RuntimeException('billing_operations_database_role_must_not_own_tables');
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

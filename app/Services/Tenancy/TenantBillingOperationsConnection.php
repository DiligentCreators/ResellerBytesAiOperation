<?php

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantBillingOperationsConnection
{
    /**
     * Run global billing reconciliation under the separately provisioned
     * platform operations role. The ordinary PostgreSQL runtime role remains
     * subject to tenant RLS and must not receive cross-tenant bypass rights.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
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
            $role = $operations->selectOne('SELECT rolbypassrls, rolsuper, rolcreatedb, rolcreaterole, rolinherit, rolcanlogin, rolreplication FROM pg_roles WHERE rolname = current_user');
            if ($runtimeRole === null || $operationsRole === null || $runtimeRole->role_name === $operationsRole->role_name
                || $runtimeRoleFlags === null || filter_var($runtimeRoleFlags->rolbypassrls, FILTER_VALIDATE_BOOLEAN)
                || filter_var($runtimeRoleFlags->rolsuper, FILTER_VALIDATE_BOOLEAN)
                || $role === null || ! filter_var($role->rolbypassrls, FILTER_VALIDATE_BOOLEAN)
                || filter_var($role->rolsuper, FILTER_VALIDATE_BOOLEAN)
                || filter_var($role->rolcreatedb, FILTER_VALIDATE_BOOLEAN)
                || filter_var($role->rolcreaterole, FILTER_VALIDATE_BOOLEAN)
                || filter_var($role->rolinherit, FILTER_VALIDATE_BOOLEAN)
                || ! filter_var($role->rolcanlogin, FILTER_VALIDATE_BOOLEAN)
                || filter_var($role->rolreplication, FILTER_VALIDATE_BOOLEAN)) {
                throw new RuntimeException('billing_operations_database_role_privileges_invalid');
            }

            $schemaPrivileges = $operations->selectOne(<<<'SQL'
SELECT has_schema_privilege(current_user, 'public', 'USAGE') AS can_use_schema,
       has_schema_privilege(current_user, 'public', 'CREATE') AS can_create_schema,
       array_to_string(current_schemas(false), ',') AS effective_search_path,
       EXISTS (
           SELECT 1 FROM pg_roles AS migrator
           WHERE migrator.rolname = 'rb_migrator'
             AND pg_has_role(current_user, migrator.oid, 'MEMBER')
       ) AS is_migrator_member
SQL);
            if ($schemaPrivileges === null
                || ! filter_var($schemaPrivileges->can_use_schema, FILTER_VALIDATE_BOOLEAN)
                || filter_var($schemaPrivileges->can_create_schema, FILTER_VALIDATE_BOOLEAN)
                || filter_var($schemaPrivileges->is_migrator_member, FILTER_VALIDATE_BOOLEAN)
                || $schemaPrivileges->effective_search_path !== 'public') {
                throw new RuntimeException('billing_operations_database_schema_privileges_invalid');
            }

            $tablePrivileges = $operations->selectOne(<<<'SQL'
SELECT COUNT(*) AS unexpected_privilege_count
FROM pg_class AS relation
JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
WHERE namespace.nspname = 'public'
  AND relation.relkind IN ('r', 'p', 'v', 'm', 'f')
  AND (
      has_table_privilege(current_user, relation.oid, 'SELECT')
      OR has_table_privilege(current_user, relation.oid, 'INSERT')
      OR has_table_privilege(current_user, relation.oid, 'UPDATE')
      OR has_table_privilege(current_user, relation.oid, 'DELETE')
      OR has_table_privilege(current_user, relation.oid, 'TRUNCATE')
      OR has_table_privilege(current_user, relation.oid, 'REFERENCES')
      OR has_table_privilege(current_user, relation.oid, 'TRIGGER')
  )
  AND (
      relation.relname NOT IN ('tenant_billing_provider_accounts', 'tenant_billing_webhook_events', 'tenant_billing_outbox')
      OR NOT has_table_privilege(current_user, relation.oid, 'SELECT')
      OR has_table_privilege(current_user, relation.oid, 'INSERT')
      OR has_table_privilege(current_user, relation.oid, 'UPDATE')
      OR has_table_privilege(current_user, relation.oid, 'DELETE')
      OR has_table_privilege(current_user, relation.oid, 'TRUNCATE')
      OR has_table_privilege(current_user, relation.oid, 'REFERENCES')
      OR has_table_privilege(current_user, relation.oid, 'TRIGGER')
  )
SQL);
            if ((int) ($tablePrivileges->unexpected_privilege_count ?? -1) !== 0) {
                throw new RuntimeException('billing_operations_database_table_privileges_invalid');
            }

            foreach (['tenant_billing_provider_accounts', 'tenant_billing_webhook_events', 'tenant_billing_outbox'] as $requiredTable) {
                $tableExists = $operations->selectOne('SELECT to_regclass(?) AS table_name', ['public.'.$requiredTable]);
                $readPrivilege = $operations->selectOne('SELECT has_table_privilege(current_user, ?, \'SELECT\') AS can_select', ['public.'.$requiredTable]);
                if (! is_string($tableExists?->table_name) || ! filter_var($readPrivilege?->can_select, FILTER_VALIDATE_BOOLEAN)) {
                    throw new RuntimeException('billing_operations_database_required_read_privilege_missing');
                }
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

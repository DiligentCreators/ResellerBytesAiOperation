<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_fix_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('integration_id')->nullable();
            $table->string('action_key', 64);
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('daily_cap')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'auto_fix_policies_id_tenant_unique');
            $table->unique(
                ['tenant_id', 'integration_id', 'action_key'],
                'auto_fix_policies_tenant_integration_action_unique',
            );
            $table->index(['tenant_id', 'enabled', 'action_key'], 'auto_fix_policies_tenant_enabled_action_idx');
            $table->foreign(['integration_id', 'tenant_id'], 'auto_fix_policies_integration_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('whmcs_integrations')
                ->cascadeOnDelete();
        });

        $this->createTenantConsistencyTriggers();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->enablePostgresRls();
        }
    }

    public function down(): void
    {
        $this->dropTenantConsistencyTriggers();

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON auto_fix_policies');
            DB::statement('ALTER TABLE auto_fix_policies DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('auto_fix_policies');
    }

    private function createTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION rb_check_auto_fix_policy_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    IF NEW.integration_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT tenant_id INTO expected_tenant FROM whmcs_integrations WHERE id = NEW.integration_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'auto_fix_policy_tenant_mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_auto_fix_policy_tenant_guard
    BEFORE INSERT OR UPDATE OF integration_id, tenant_id ON auto_fix_policies
    FOR EACH ROW EXECUTE FUNCTION rb_check_auto_fix_policy_tenant();
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_auto_fix_policy_tenant_guard_insert BEFORE INSERT ON auto_fix_policies
WHEN NEW.integration_id IS NOT NULL AND NEW.tenant_id IS NOT (SELECT tenant_id FROM whmcs_integrations WHERE id = NEW.integration_id)
BEGIN SELECT RAISE(ABORT, 'auto_fix_policy_tenant_mismatch'); END;
CREATE TRIGGER rb_auto_fix_policy_tenant_guard_update BEFORE UPDATE OF integration_id, tenant_id ON auto_fix_policies
WHEN NEW.integration_id IS NOT NULL AND NEW.tenant_id IS NOT (SELECT tenant_id FROM whmcs_integrations WHERE id = NEW.integration_id)
BEGIN SELECT RAISE(ABORT, 'auto_fix_policy_tenant_mismatch'); END;
SQL);
        }
    }

    private function dropTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_auto_fix_policy_tenant_guard ON auto_fix_policies');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_auto_fix_policy_tenant()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_auto_fix_policy_tenant_guard_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_auto_fix_policy_tenant_guard_update');
        }
    }

    private function enablePostgresRls(): void
    {
        DB::statement('ALTER TABLE auto_fix_policies ENABLE ROW LEVEL SECURITY');
        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = auto_fix_policies.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
        DB::statement("CREATE POLICY tenant_isolation_policy ON auto_fix_policies USING ({$scope}) WITH CHECK ({$scope})");
    }
};

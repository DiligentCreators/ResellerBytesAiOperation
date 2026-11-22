<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whmcs_health_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('integration_id');
            $table->uuid('diagnostic_run_id')->nullable();
            $table->timestamp('captured_at');
            $table->date('captured_on');
            $table->unsignedTinyInteger('score');
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);
            $table->json('metrics');
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'whmcs_health_snapshots_id_tenant_unique');
            $table->unique(['integration_id', 'captured_on'], 'whmcs_health_snapshots_integration_day_unique');
            $table->foreign(['integration_id', 'tenant_id'], 'whmcs_health_snapshots_integration_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('whmcs_integrations')
                ->cascadeOnDelete();
            $table->foreign(['diagnostic_run_id', 'tenant_id'], 'whmcs_health_snapshots_run_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('diagnostic_runs')
                ->nullOnDelete();
            $table->index(['tenant_id', 'captured_on'], 'whmcs_health_snapshots_tenant_day_idx');
            $table->index(['tenant_id', 'integration_id', 'captured_on'], 'whmcs_health_snapshots_tenant_integration_day_idx');
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
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON whmcs_health_snapshots');
            DB::statement('ALTER TABLE whmcs_health_snapshots DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('whmcs_health_snapshots');
    }

    private function createTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION rb_check_whmcs_health_snapshot_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
DECLARE expected_run_tenant uuid;
BEGIN
    SELECT tenant_id INTO expected_tenant FROM whmcs_integrations WHERE id = NEW.integration_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'whmcs_health_snapshot_tenant_mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.diagnostic_run_id IS NOT NULL THEN
        SELECT tenant_id INTO expected_run_tenant FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_run_tenant THEN
            RAISE EXCEPTION 'whmcs_health_snapshot_run_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_whmcs_health_snapshot_tenant_guard
    BEFORE INSERT OR UPDATE OF integration_id, diagnostic_run_id, tenant_id ON whmcs_health_snapshots
    FOR EACH ROW EXECUTE FUNCTION rb_check_whmcs_health_snapshot_tenant();
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_whmcs_health_snapshot_tenant_guard_insert BEFORE INSERT ON whmcs_health_snapshots
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM whmcs_integrations WHERE id = NEW.integration_id)
BEGIN SELECT RAISE(ABORT, 'whmcs_health_snapshot_tenant_mismatch'); END;
CREATE TRIGGER rb_whmcs_health_snapshot_tenant_guard_update BEFORE UPDATE OF integration_id, diagnostic_run_id, tenant_id ON whmcs_health_snapshots
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM whmcs_integrations WHERE id = NEW.integration_id)
BEGIN SELECT RAISE(ABORT, 'whmcs_health_snapshot_tenant_mismatch'); END;
CREATE TRIGGER rb_whmcs_health_snapshot_run_tenant_guard_insert BEFORE INSERT ON whmcs_health_snapshots
WHEN NEW.diagnostic_run_id IS NOT NULL
 AND NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id)
BEGIN SELECT RAISE(ABORT, 'whmcs_health_snapshot_run_tenant_mismatch'); END;
CREATE TRIGGER rb_whmcs_health_snapshot_run_tenant_guard_update BEFORE UPDATE OF integration_id, diagnostic_run_id, tenant_id ON whmcs_health_snapshots
WHEN NEW.diagnostic_run_id IS NOT NULL
 AND NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id)
BEGIN SELECT RAISE(ABORT, 'whmcs_health_snapshot_run_tenant_mismatch'); END;
SQL);
        }
    }

    private function dropTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_whmcs_health_snapshot_tenant_guard ON whmcs_health_snapshots');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_whmcs_health_snapshot_tenant()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_whmcs_health_snapshot_tenant_guard_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_whmcs_health_snapshot_tenant_guard_update');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_whmcs_health_snapshot_run_tenant_guard_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_whmcs_health_snapshot_run_tenant_guard_update');
        }
    }

    private function enablePostgresRls(): void
    {
        DB::statement('ALTER TABLE whmcs_health_snapshots ENABLE ROW LEVEL SECURITY');
        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = whmcs_health_snapshots.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
        DB::statement("CREATE POLICY tenant_isolation_policy ON whmcs_health_snapshots USING ({$scope}) WITH CHECK ({$scope})");
    }
};

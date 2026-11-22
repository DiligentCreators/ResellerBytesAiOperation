<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'safe_action_executions_id_tenant_unique');
        });

        Schema::create('safe_action_late_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('safe_action_execution_id');
            $table->string('status', 32);
            $table->json('payload_summary');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'safe_action_late_results_id_tenant_unique');
            $table->foreign(['safe_action_execution_id', 'tenant_id'], 'safe_action_late_results_execution_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('safe_action_executions')
                ->cascadeOnDelete();
            $table->index(
                ['tenant_id', 'safe_action_execution_id', 'received_at'],
                'safe_action_late_results_execution_received_idx'
            );
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
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON safe_action_late_results');
            DB::statement('ALTER TABLE safe_action_late_results DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('safe_action_late_results');

        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->dropUnique('safe_action_executions_id_tenant_unique');
        });
    }

    private function createTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION rb_check_safe_action_late_result_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    SELECT tenant_id INTO expected_tenant FROM safe_action_executions WHERE id = NEW.safe_action_execution_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'safe_action_late_result_tenant_mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_safe_action_late_result_tenant_guard
    BEFORE INSERT OR UPDATE OF safe_action_execution_id, tenant_id ON safe_action_late_results
    FOR EACH ROW EXECUTE FUNCTION rb_check_safe_action_late_result_tenant();
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_safe_action_late_result_tenant_guard_insert BEFORE INSERT ON safe_action_late_results
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM safe_action_executions WHERE id = NEW.safe_action_execution_id)
BEGIN SELECT RAISE(ABORT, 'safe_action_late_result_tenant_mismatch'); END;
CREATE TRIGGER rb_safe_action_late_result_tenant_guard_update BEFORE UPDATE OF safe_action_execution_id, tenant_id ON safe_action_late_results
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM safe_action_executions WHERE id = NEW.safe_action_execution_id)
BEGIN SELECT RAISE(ABORT, 'safe_action_late_result_tenant_mismatch'); END;
SQL);
        }
    }

    private function dropTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_safe_action_late_result_tenant_guard ON safe_action_late_results');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_safe_action_late_result_tenant()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_safe_action_late_result_tenant_guard_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_safe_action_late_result_tenant_guard_update');
        }
    }

    private function enablePostgresRls(): void
    {
        DB::statement('ALTER TABLE safe_action_late_results ENABLE ROW LEVEL SECURITY');
        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = safe_action_late_results.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
        DB::statement("CREATE POLICY tenant_isolation_policy ON safe_action_late_results USING ({$scope}) WITH CHECK ({$scope})");
    }
};

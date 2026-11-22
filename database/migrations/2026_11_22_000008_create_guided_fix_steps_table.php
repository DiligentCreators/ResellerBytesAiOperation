<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guided_fix_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('finding_id');
            $table->unsignedSmallInteger('step_index');
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'guided_fix_steps_id_tenant_unique');
            $table->unique(['finding_id', 'step_index'], 'guided_fix_steps_finding_step_unique');
            $table->foreign(['finding_id', 'tenant_id'], 'guided_fix_steps_finding_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('diagnostic_findings')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'finding_id'], 'guided_fix_steps_tenant_finding_idx');
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
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON guided_fix_steps');
            DB::statement('ALTER TABLE guided_fix_steps DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('guided_fix_steps');
    }

    private function createTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION rb_check_guided_fix_step_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    SELECT tenant_id INTO expected_tenant FROM diagnostic_findings WHERE id = NEW.finding_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'guided_fix_step_tenant_mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_guided_fix_step_tenant_guard
    BEFORE INSERT OR UPDATE OF finding_id, tenant_id ON guided_fix_steps
    FOR EACH ROW EXECUTE FUNCTION rb_check_guided_fix_step_tenant();
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_guided_fix_step_tenant_guard_insert BEFORE INSERT ON guided_fix_steps
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_findings WHERE id = NEW.finding_id)
BEGIN SELECT RAISE(ABORT, 'guided_fix_step_tenant_mismatch'); END;
CREATE TRIGGER rb_guided_fix_step_tenant_guard_update BEFORE UPDATE OF finding_id, tenant_id ON guided_fix_steps
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_findings WHERE id = NEW.finding_id)
BEGIN SELECT RAISE(ABORT, 'guided_fix_step_tenant_mismatch'); END;
SQL);
        }
    }

    private function dropTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_guided_fix_step_tenant_guard ON guided_fix_steps');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_guided_fix_step_tenant()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_guided_fix_step_tenant_guard_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_guided_fix_step_tenant_guard_update');
        }
    }

    private function enablePostgresRls(): void
    {
        DB::statement('ALTER TABLE guided_fix_steps ENABLE ROW LEVEL SECURITY');
        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = guided_fix_steps.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
        DB::statement("CREATE POLICY tenant_isolation_policy ON guided_fix_steps USING ({$scope}) WITH CHECK ({$scope})");
    }
};

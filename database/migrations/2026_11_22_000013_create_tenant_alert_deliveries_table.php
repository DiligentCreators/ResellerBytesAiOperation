<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_alert_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('channel_id');
            $table->string('alert_key', 64);
            $table->string('dedupe_key', 128);
            $table->string('status', 32);
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'tenant_alert_deliveries_id_tenant_unique');
            $table->index('dedupe_key', 'tenant_alert_deliveries_dedupe_key_idx');
            $table->index(['tenant_id', 'channel_id', 'dedupe_key'], 'tenant_alert_deliveries_dedupe_lookup_idx');
            $table->index(['tenant_id', 'created_at'], 'tenant_alert_deliveries_tenant_created_idx');
            $table->foreign(['channel_id', 'tenant_id'], 'tenant_alert_deliveries_channel_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('tenant_alert_channels')
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
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON tenant_alert_deliveries');
            DB::statement('ALTER TABLE tenant_alert_deliveries DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('tenant_alert_deliveries');
    }

    private function createTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION rb_check_tenant_alert_delivery_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    SELECT tenant_id INTO expected_tenant FROM tenant_alert_channels WHERE id = NEW.channel_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'tenant_alert_delivery_tenant_mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_tenant_alert_delivery_tenant_guard
    BEFORE INSERT OR UPDATE OF channel_id, tenant_id ON tenant_alert_deliveries
    FOR EACH ROW EXECUTE FUNCTION rb_check_tenant_alert_delivery_tenant();
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_tenant_alert_delivery_tenant_guard_insert BEFORE INSERT ON tenant_alert_deliveries
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM tenant_alert_channels WHERE id = NEW.channel_id)
BEGIN SELECT RAISE(ABORT, 'tenant_alert_delivery_tenant_mismatch'); END;
CREATE TRIGGER rb_tenant_alert_delivery_tenant_guard_update BEFORE UPDATE OF channel_id, tenant_id ON tenant_alert_deliveries
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM tenant_alert_channels WHERE id = NEW.channel_id)
BEGIN SELECT RAISE(ABORT, 'tenant_alert_delivery_tenant_mismatch'); END;
SQL);
        }
    }

    private function dropTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_alert_delivery_tenant_guard ON tenant_alert_deliveries');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_tenant_alert_delivery_tenant()');

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_alert_delivery_tenant_guard_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_alert_delivery_tenant_guard_update');
        }
    }

    private function enablePostgresRls(): void
    {
        DB::statement('ALTER TABLE tenant_alert_deliveries ENABLE ROW LEVEL SECURITY');
        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenant_alert_deliveries.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
        DB::statement("CREATE POLICY tenant_isolation_policy ON tenant_alert_deliveries USING ({$scope}) WITH CHECK ({$scope})");
    }
};

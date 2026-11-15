<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_billing_payment_id');
            $table->foreign(['tenant_billing_payment_id', 'tenant_id'], 'tenant_billing_adjustment_payment_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('tenant_billing_payments')
                ->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account_id', 160);
            $table->string('provider_adjustment_id', 180);
            $table->string('kind', 24);
            $table->string('idempotency_key', 160);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'tenant_billing_adjustment_idempotency_unique');
            $table->unique(['tenant_id', 'provider', 'provider_account_id', 'provider_adjustment_id', 'kind'], 'tenant_billing_adjustment_provider_unique');
            $table->index(['tenant_id', 'tenant_billing_payment_id', 'kind'], 'tenant_billing_adjustment_payment_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tenant_billing_adjustments ENABLE ROW LEVEL SECURITY');
            $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenant_billing_adjustments.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
            $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
            DB::statement("CREATE POLICY tenant_isolation_policy ON tenant_billing_adjustments USING ({$scope}) WITH CHECK ({$scope})");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION rb_guard_tenant_billing_adjustment_immutable() RETURNS trigger LANGUAGE plpgsql AS $rb$
BEGIN
    RAISE EXCEPTION 'tenant_billing_adjustment_append_only' USING ERRCODE = '23514';
END;
$rb$;
CREATE FUNCTION rb_check_billing_adjustment_audit_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    IF NEW.subject_type = 'tenant_billing_adjustment' THEN
        SELECT tenant_id INTO expected_tenant FROM tenant_billing_adjustments WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_tenant_billing_adjustment_immutable BEFORE UPDATE OR DELETE ON tenant_billing_adjustments FOR EACH ROW EXECUTE FUNCTION rb_guard_tenant_billing_adjustment_immutable();
CREATE TRIGGER rb_billing_adjustment_audit_tenant_guard BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION rb_check_billing_adjustment_audit_tenant();
SQL);
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_tenant_billing_adjustment_immutable BEFORE UPDATE ON tenant_billing_adjustments
BEGIN SELECT RAISE(ABORT, 'tenant_billing_adjustment_append_only'); END;
CREATE TRIGGER rb_tenant_billing_adjustment_no_delete BEFORE DELETE ON tenant_billing_adjustments
BEGIN SELECT RAISE(ABORT, 'tenant_billing_adjustment_append_only'); END;
CREATE TRIGGER rb_billing_adjustment_audit_tenant_guard BEFORE INSERT ON audit_events
WHEN NEW.subject_type = 'tenant_billing_adjustment'
 AND NEW.tenant_id IS NOT (SELECT tenant_id FROM tenant_billing_adjustments WHERE id = CAST(NEW.subject_id AS INTEGER))
BEGIN SELECT RAISE(ABORT, 'audit_event_tenant_mismatch'); END;
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_adjustment_immutable ON tenant_billing_adjustments');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_billing_adjustment_audit_tenant_guard ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_guard_tenant_billing_adjustment_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_billing_adjustment_audit_tenant()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_adjustment_immutable');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_adjustment_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_billing_adjustment_audit_tenant_guard');
        }

        Schema::dropIfExists('tenant_billing_adjustments');
    }
};

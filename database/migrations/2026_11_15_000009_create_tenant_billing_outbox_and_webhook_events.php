<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->string('event_type', 120);
            $table->string('aggregate_type', 60);
            $table->string('aggregate_id', 120);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'tenant_billing_outbox_idempotency_unique');
            $table->unique(['id', 'tenant_id'], 'tenant_billing_outbox_id_tenant_unique');
            $table->index(['tenant_id', 'status', 'available_at'], 'tenant_billing_outbox_dispatch_idx');
        });

        Schema::create('tenant_billing_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account_id', 160);
            $table->string('provider_event_id', 180);
            $table->string('event_type', 120);
            $table->char('payload_sha256', 64);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('received_at');
            $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider', 'provider_event_id'], 'tenant_billing_webhook_idempotency_unique');
            $table->unique(['provider', 'provider_account_id', 'provider_event_id'], 'tenant_billing_webhook_provider_event_unique');
            $table->unique(['id', 'tenant_id'], 'tenant_billing_webhook_id_tenant_unique');
            $table->index(['tenant_id', 'status', 'available_at'], 'tenant_billing_webhook_dispatch_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach (['tenant_billing_outbox', 'tenant_billing_webhook_events'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = {$table}.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
                $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
                DB::statement("CREATE POLICY tenant_isolation_policy ON {$table} USING ({$scope}) WITH CHECK ({$scope})");
            }

            DB::unprepared(<<<'SQL'
CREATE FUNCTION rb_guard_tenant_billing_outbox_immutable() RETURNS trigger LANGUAGE plpgsql AS $rb$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
        OR NEW.event_type IS DISTINCT FROM OLD.event_type
        OR NEW.aggregate_type IS DISTINCT FROM OLD.aggregate_type
        OR NEW.aggregate_id IS DISTINCT FROM OLD.aggregate_id
        OR NEW.payload IS DISTINCT FROM OLD.payload THEN
        RAISE EXCEPTION 'tenant_billing_outbox_immutable_fields' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE FUNCTION rb_guard_tenant_billing_webhook_immutable() RETURNS trigger LANGUAGE plpgsql AS $rb$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
        OR NEW.provider IS DISTINCT FROM OLD.provider
        OR NEW.provider_account_id IS DISTINCT FROM OLD.provider_account_id
        OR NEW.provider_event_id IS DISTINCT FROM OLD.provider_event_id
        OR NEW.event_type IS DISTINCT FROM OLD.event_type
        OR NEW.payload_sha256 IS DISTINCT FROM OLD.payload_sha256
        OR NEW.payload IS DISTINCT FROM OLD.payload
        OR NEW.received_at IS DISTINCT FROM OLD.received_at THEN
        RAISE EXCEPTION 'tenant_billing_webhook_immutable_fields' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE FUNCTION rb_check_billing_webhook_audit_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    IF NEW.subject_type = 'tenant_billing_webhook_event' THEN
        SELECT tenant_id INTO expected_tenant FROM tenant_billing_webhook_events WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_tenant_billing_outbox_immutable BEFORE UPDATE ON tenant_billing_outbox FOR EACH ROW EXECUTE FUNCTION rb_guard_tenant_billing_outbox_immutable();
CREATE TRIGGER rb_tenant_billing_webhook_immutable BEFORE UPDATE ON tenant_billing_webhook_events FOR EACH ROW EXECUTE FUNCTION rb_guard_tenant_billing_webhook_immutable();
CREATE TRIGGER rb_billing_webhook_audit_tenant_guard BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION rb_check_billing_webhook_audit_tenant();
SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_tenant_billing_outbox_immutable BEFORE UPDATE ON tenant_billing_outbox
WHEN NEW.tenant_id IS NOT OLD.tenant_id
 OR NEW.idempotency_key IS NOT OLD.idempotency_key
 OR NEW.event_type IS NOT OLD.event_type
 OR NEW.aggregate_type IS NOT OLD.aggregate_type
 OR NEW.aggregate_id IS NOT OLD.aggregate_id
 OR NEW.payload IS NOT OLD.payload
BEGIN SELECT RAISE(ABORT, 'tenant_billing_outbox_immutable_fields'); END;
CREATE TRIGGER rb_tenant_billing_webhook_immutable BEFORE UPDATE ON tenant_billing_webhook_events
WHEN NEW.tenant_id IS NOT OLD.tenant_id
 OR NEW.provider IS NOT OLD.provider
 OR NEW.provider_account_id IS NOT OLD.provider_account_id
 OR NEW.provider_event_id IS NOT OLD.provider_event_id
 OR NEW.event_type IS NOT OLD.event_type
 OR NEW.payload_sha256 IS NOT OLD.payload_sha256
 OR NEW.payload IS NOT OLD.payload
 OR NEW.received_at IS NOT OLD.received_at
BEGIN SELECT RAISE(ABORT, 'tenant_billing_webhook_immutable_fields'); END;
CREATE TRIGGER rb_billing_webhook_audit_tenant_guard BEFORE INSERT ON audit_events
WHEN NEW.subject_type = 'tenant_billing_webhook_event'
 AND NEW.tenant_id IS NOT (SELECT tenant_id FROM tenant_billing_webhook_events WHERE id = CAST(NEW.subject_id AS INTEGER))
BEGIN SELECT RAISE(ABORT, 'audit_event_tenant_mismatch'); END;
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_outbox_immutable ON tenant_billing_outbox');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_webhook_immutable ON tenant_billing_webhook_events');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_billing_webhook_audit_tenant_guard ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_guard_tenant_billing_outbox_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_guard_tenant_billing_webhook_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_billing_webhook_audit_tenant()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_outbox_immutable');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_webhook_immutable');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_billing_webhook_audit_tenant_guard');
        }

        Schema::dropIfExists('tenant_billing_webhook_events');
        Schema::dropIfExists('tenant_billing_outbox');
    }
};

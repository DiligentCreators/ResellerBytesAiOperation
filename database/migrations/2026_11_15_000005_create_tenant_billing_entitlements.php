<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->string('plan_code', 64);
            $table->string('billing_mode', 16);
            $table->string('status', 20)->index();
            $table->string('billing_provider', 40)->nullable();
            $table->string('provider_account_id', 160)->nullable();
            $table->string('provider_subscription_id', 160)->nullable()->unique();
            $table->string('currency', 3);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->jsonb('entitlements');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('current_period_starts_at');
            $table->timestampTz('current_period_ends_at');
            $table->timestampTz('grace_ends_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'current_period_ends_at'], 'tenant_subscription_entitlement_lookup');
        });

        Schema::create('tenant_billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->string('invoice_number', 80)->unique();
            $table->string('provider', 40)->nullable();
            $table->string('provider_account_id', 160)->nullable();
            $table->string('provider_invoice_id', 180)->nullable();
            $table->string('status', 20)->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('amount_due_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->json('line_items')->nullable();
            $table->json('billing_details')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->unique(['tenant_id', 'provider', 'provider_account_id', 'provider_invoice_id'], 'tenant_billing_provider_invoice_unique');
        });

        Schema::create('tenant_billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_billing_invoice_id')->constrained('tenant_billing_invoices')->restrictOnDelete();
            $table->string('collection_mode', 16);
            $table->string('idempotency_key', 120)->unique();
            $table->string('provider', 40)->nullable();
            $table->string('provider_account_id', 160)->nullable();
            $table->string('provider_payment_id', 160)->nullable()->unique();
            $table->string('status', 20)->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 160)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'received_at']);
            $table->unique(['id', 'tenant_id'], 'tenant_billing_payment_id_tenant_unique');
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX tenant_subscriptions_one_current_per_tenant ON tenant_subscriptions (tenant_id) WHERE status IN ('trialing', 'active', 'past_due', 'suspended')");
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION rb_guard_tenant_subscription_provider_identity() RETURNS trigger LANGUAGE plpgsql AS $rb$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
        OR (OLD.billing_provider IS NOT NULL AND NEW.billing_provider IS DISTINCT FROM OLD.billing_provider)
        OR (OLD.provider_account_id IS NOT NULL AND NEW.provider_account_id IS DISTINCT FROM OLD.provider_account_id)
        OR (OLD.provider_subscription_id IS NOT NULL AND NEW.provider_subscription_id IS DISTINCT FROM OLD.provider_subscription_id) THEN
        RAISE EXCEPTION 'tenant_subscription_provider_identity_immutable' USING ERRCODE = '23514';
    END IF;
    IF NEW.plan_code IS DISTINCT FROM OLD.plan_code
        OR NEW.billing_mode IS DISTINCT FROM OLD.billing_mode
        OR NEW.currency IS DISTINCT FROM OLD.currency
        OR NEW.unit_amount_minor IS DISTINCT FROM OLD.unit_amount_minor
        OR NEW.entitlements IS DISTINCT FROM OLD.entitlements THEN
        RAISE EXCEPTION 'tenant_subscription_plan_snapshot_immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE OR REPLACE FUNCTION rb_guard_tenant_billing_invoice_provider_identity() RETURNS trigger LANGUAGE plpgsql AS $rb$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
        OR (OLD.tenant_subscription_id IS NOT NULL AND NEW.tenant_subscription_id IS DISTINCT FROM OLD.tenant_subscription_id)
        OR (OLD.provider IS NOT NULL AND NEW.provider IS DISTINCT FROM OLD.provider)
        OR (OLD.provider_account_id IS NOT NULL AND NEW.provider_account_id IS DISTINCT FROM OLD.provider_account_id)
        OR (OLD.provider_invoice_id IS NOT NULL AND NEW.provider_invoice_id IS DISTINCT FROM OLD.provider_invoice_id) THEN
        RAISE EXCEPTION 'tenant_billing_invoice_provider_identity_immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE OR REPLACE FUNCTION rb_guard_tenant_billing_payment_immutable() RETURNS trigger LANGUAGE plpgsql AS $rb$
BEGIN
    RAISE EXCEPTION 'tenant_billing_payment_append_only' USING ERRCODE = '23514';
END;
$rb$;
CREATE OR REPLACE FUNCTION rb_check_billing_payment_audit_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    IF NEW.subject_type = 'tenant_billing_payment' THEN
        SELECT tenant_id INTO expected_tenant FROM tenant_billing_payments WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_tenant_subscription_provider_identity BEFORE UPDATE ON tenant_subscriptions FOR EACH ROW EXECUTE FUNCTION rb_guard_tenant_subscription_provider_identity();
CREATE TRIGGER rb_tenant_billing_invoice_provider_identity BEFORE UPDATE ON tenant_billing_invoices FOR EACH ROW EXECUTE FUNCTION rb_guard_tenant_billing_invoice_provider_identity();
CREATE TRIGGER rb_tenant_billing_payment_immutable BEFORE UPDATE OR DELETE ON tenant_billing_payments FOR EACH ROW EXECUTE FUNCTION rb_guard_tenant_billing_payment_immutable();
CREATE TRIGGER rb_billing_payment_audit_tenant_guard BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION rb_check_billing_payment_audit_tenant();
SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_tenant_subscription_provider_identity BEFORE UPDATE ON tenant_subscriptions
WHEN NEW.tenant_id IS NOT OLD.tenant_id
 OR (OLD.billing_provider IS NOT NULL AND NEW.billing_provider IS NOT OLD.billing_provider)
 OR (OLD.provider_account_id IS NOT NULL AND NEW.provider_account_id IS NOT OLD.provider_account_id)
 OR (OLD.provider_subscription_id IS NOT NULL AND NEW.provider_subscription_id IS NOT OLD.provider_subscription_id)
BEGIN SELECT RAISE(ABORT, 'tenant_subscription_provider_identity_immutable'); END;
CREATE TRIGGER rb_tenant_subscription_plan_snapshot_immutable BEFORE UPDATE ON tenant_subscriptions
WHEN NEW.plan_code IS NOT OLD.plan_code
 OR NEW.billing_mode IS NOT OLD.billing_mode
 OR NEW.currency IS NOT OLD.currency
 OR NEW.unit_amount_minor IS NOT OLD.unit_amount_minor
 OR NEW.entitlements IS NOT OLD.entitlements
BEGIN SELECT RAISE(ABORT, 'tenant_subscription_plan_snapshot_immutable'); END;
CREATE TRIGGER rb_tenant_billing_invoice_provider_identity BEFORE UPDATE ON tenant_billing_invoices
WHEN NEW.tenant_id IS NOT OLD.tenant_id
 OR (OLD.tenant_subscription_id IS NOT NULL AND NEW.tenant_subscription_id IS NOT OLD.tenant_subscription_id)
 OR (OLD.provider IS NOT NULL AND NEW.provider IS NOT OLD.provider)
 OR (OLD.provider_account_id IS NOT NULL AND NEW.provider_account_id IS NOT OLD.provider_account_id)
 OR (OLD.provider_invoice_id IS NOT NULL AND NEW.provider_invoice_id IS NOT OLD.provider_invoice_id)
BEGIN SELECT RAISE(ABORT, 'tenant_billing_invoice_provider_identity_immutable'); END;
CREATE TRIGGER rb_tenant_billing_payment_immutable BEFORE UPDATE ON tenant_billing_payments
BEGIN SELECT RAISE(ABORT, 'tenant_billing_payment_append_only'); END;
CREATE TRIGGER rb_tenant_billing_payment_no_delete BEFORE DELETE ON tenant_billing_payments
BEGIN SELECT RAISE(ABORT, 'tenant_billing_payment_append_only'); END;
CREATE TRIGGER rb_billing_payment_audit_tenant_guard BEFORE INSERT ON audit_events
WHEN NEW.subject_type = 'tenant_billing_payment'
 AND NEW.tenant_id IS NOT (SELECT tenant_id FROM tenant_billing_payments WHERE id = CAST(NEW.subject_id AS INTEGER))
BEGIN SELECT RAISE(ABORT, 'audit_event_tenant_mismatch'); END;
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS tenant_subscriptions_one_current_per_tenant');
        }
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_subscription_provider_identity ON tenant_subscriptions');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_subscription_plan_snapshot_immutable ON tenant_subscriptions');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_payment_immutable ON tenant_billing_payments');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_invoice_provider_identity ON tenant_billing_invoices');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_billing_payment_audit_tenant_guard ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_guard_tenant_subscription_provider_identity()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_guard_tenant_billing_payment_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_guard_tenant_billing_invoice_provider_identity()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_billing_payment_audit_tenant()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_subscription_provider_identity');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_subscription_plan_snapshot_immutable');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_payment_immutable');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_invoice_provider_identity');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_payment_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS rb_billing_payment_audit_tenant_guard');
        }

        Schema::dropIfExists('tenant_billing_payments');
        Schema::dropIfExists('tenant_billing_invoices');
        Schema::dropIfExists('tenant_subscriptions');
    }
};

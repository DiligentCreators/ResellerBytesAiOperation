<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account_id', 160);
            $table->string('status', 20)->default('inactive');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_account_id'], 'tenant_billing_provider_account_unique');
            $table->index(['tenant_id', 'status'], 'tenant_billing_provider_account_tenant_status_idx');
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tenant_billing_provider_accounts ENABLE ROW LEVEL SECURITY');
            $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenant_billing_provider_accounts.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
            $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
            DB::statement("CREATE POLICY tenant_isolation_policy ON tenant_billing_provider_accounts USING ({$scope}) WITH CHECK ({$scope})");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION rb_guard_tenant_billing_provider_account_identity() RETURNS trigger LANGUAGE plpgsql AS $rb$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
        OR NEW.provider IS DISTINCT FROM OLD.provider
        OR NEW.provider_account_id IS DISTINCT FROM OLD.provider_account_id THEN
        RAISE EXCEPTION 'tenant_billing_provider_account_identity_immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_tenant_billing_provider_account_identity BEFORE UPDATE ON tenant_billing_provider_accounts FOR EACH ROW EXECUTE FUNCTION rb_guard_tenant_billing_provider_account_identity();
SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_tenant_billing_provider_account_identity BEFORE UPDATE ON tenant_billing_provider_accounts
WHEN NEW.tenant_id IS NOT OLD.tenant_id
 OR NEW.provider IS NOT OLD.provider
 OR NEW.provider_account_id IS NOT OLD.provider_account_id
BEGIN SELECT RAISE(ABORT, 'tenant_billing_provider_account_identity_immutable'); END;
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_provider_account_identity ON tenant_billing_provider_accounts');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_guard_tenant_billing_provider_account_identity()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS rb_tenant_billing_provider_account_identity');
        }

        Schema::dropIfExists('tenant_billing_provider_accounts');
    }
};

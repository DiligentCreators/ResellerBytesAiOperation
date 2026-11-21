<?php

use App\Services\Billing\BackfillTenantSubscriptionChargePeriods;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->string('charge_period', 16)->nullable()->after('unit_amount_minor');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            // Schema::table rebuilds can turn the partial unique index into a full
            // UNIQUE(tenant_id) and drop table triggers. Recreate both.
            DB::statement('DROP INDEX IF EXISTS tenant_subscriptions_one_current_per_tenant');
            DB::statement("CREATE UNIQUE INDEX tenant_subscriptions_one_current_per_tenant ON tenant_subscriptions (tenant_id) WHERE status IN ('trialing', 'active', 'past_due', 'suspended')");

            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS rb_tenant_subscription_provider_identity;
DROP TRIGGER IF EXISTS rb_tenant_subscription_plan_snapshot_immutable;
DROP TRIGGER IF EXISTS rb_tenant_billing_invoice_provider_identity;
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
SQL);
        }

        app(BackfillTenantSubscriptionChargePeriods::class)();
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('charge_period');
        });
    }
};

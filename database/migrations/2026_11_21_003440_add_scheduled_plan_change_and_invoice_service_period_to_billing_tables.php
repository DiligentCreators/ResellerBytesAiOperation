<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->foreignId('scheduled_plan_id')
                ->nullable()
                ->after('replaces_subscription_id')
                ->constrained('plans')
                ->nullOnDelete();
            $table->string('scheduled_charge_period', 16)->nullable()->after('scheduled_plan_id');
            $table->timestampTz('scheduled_change_at')->nullable()->after('scheduled_charge_period')->index();
        });

        Schema::table('tenant_billing_invoices', function (Blueprint $table): void {
            $table->timestampTz('service_period_starts_at')->nullable()->after('paid_at');
            $table->timestampTz('service_period_ends_at')->nullable()->after('service_period_starts_at');
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

        // Backfill service period from the related subscription period when present.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE tenant_billing_invoices AS invoices
                SET
                    service_period_starts_at = subs.current_period_starts_at,
                    service_period_ends_at = subs.current_period_ends_at
                FROM tenant_subscriptions AS subs
                WHERE invoices.tenant_subscription_id = subs.id
                  AND invoices.service_period_starts_at IS NULL
                  AND invoices.service_period_ends_at IS NULL
            SQL);
        } else {
            DB::table('tenant_billing_invoices')
                ->orderBy('id')
                ->chunkById(200, function ($invoices): void {
                    foreach ($invoices as $invoice) {
                        if ($invoice->tenant_subscription_id === null) {
                            continue;
                        }

                        $subscription = DB::table('tenant_subscriptions')
                            ->where('id', $invoice->tenant_subscription_id)
                            ->first(['current_period_starts_at', 'current_period_ends_at']);

                        if ($subscription === null) {
                            continue;
                        }

                        DB::table('tenant_billing_invoices')
                            ->where('id', $invoice->id)
                            ->whereNull('service_period_starts_at')
                            ->whereNull('service_period_ends_at')
                            ->update([
                                'service_period_starts_at' => $subscription->current_period_starts_at,
                                'service_period_ends_at' => $subscription->current_period_ends_at,
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('tenant_billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['service_period_starts_at', 'service_period_ends_at']);
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn(['scheduled_charge_period', 'scheduled_change_at']);
        });
    }
};

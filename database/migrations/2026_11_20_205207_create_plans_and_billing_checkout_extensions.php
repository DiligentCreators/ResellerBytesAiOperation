<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_monthly_minor');
            $table->unsignedBigInteger('price_yearly_minor');
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->json('entitlements');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_contact_sales')->default(false);
            $table->string('kelviq_plan_identifier', 160)->nullable();
            $table->timestampsTz();
        });

        Schema::create('billing_invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampsTz();
        });

        Schema::create('platform_billing_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('seller')->nullable();
            $table->timestampsTz();
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            foreach ([
                'rb_tenant_subscription_provider_identity',
                'rb_tenant_subscription_plan_snapshot_immutable',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }

        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });

        if ($driver === 'sqlite') {
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
SQL);
        }
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
        });

        Schema::dropIfExists('platform_billing_settings');
        Schema::dropIfExists('billing_invoice_sequences');
        Schema::dropIfExists('plans');
    }
};

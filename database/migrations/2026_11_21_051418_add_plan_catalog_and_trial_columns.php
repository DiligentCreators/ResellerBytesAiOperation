<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('features')->nullable()->after('entitlements');
            $table->boolean('trial_enabled')->default(false)->after('trial_days');
            $table->boolean('monthly_active')->default(true)->after('is_contact_sales');
            $table->boolean('yearly_active')->default(true)->after('monthly_active');
            $table->timestampTz('prices_confirmed_at')->nullable()->after('yearly_active');
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_subscriptions', 'trial_ends_at')) {
                $table->timestampTz('trial_ends_at')->nullable()->after('current_period_ends_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'features',
                'trial_enabled',
                'monthly_active',
                'yearly_active',
                'prices_confirmed_at',
            ]);
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_subscriptions', 'trial_ends_at')) {
                $table->dropColumn('trial_ends_at');
            }
        });
    }
};

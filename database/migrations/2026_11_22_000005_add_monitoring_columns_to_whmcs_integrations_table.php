<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table): void {
            $table->timestamp('last_daily_cron_at')->nullable()->after('last_cron_at');
            $table->string('collector_schema_version', 8)->nullable()->after('cron_php_version');
            $table->boolean('auto_collect_enabled')->default(true)->after('collector_schema_version');
            $table->timestamp('last_auto_collect_at')->nullable()->after('auto_collect_enabled');
            $table->string('heartbeat_state', 16)->default('unknown')->after('last_auto_collect_at');
            $table->timestamp('heartbeat_state_changed_at')->nullable()->after('heartbeat_state');

            $table->index('heartbeat_state', 'whmcs_integrations_heartbeat_state_index');
            $table->index('last_heartbeat_at', 'whmcs_integrations_last_heartbeat_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table): void {
            $table->dropIndex('whmcs_integrations_heartbeat_state_index');
            $table->dropIndex('whmcs_integrations_last_heartbeat_at_index');
            $table->dropColumn([
                'last_daily_cron_at',
                'collector_schema_version',
                'auto_collect_enabled',
                'last_auto_collect_at',
                'heartbeat_state',
                'heartbeat_state_changed_at',
            ]);
        });
    }
};

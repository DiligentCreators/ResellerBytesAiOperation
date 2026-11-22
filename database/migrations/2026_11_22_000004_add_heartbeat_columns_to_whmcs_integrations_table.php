<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table) {
            $table->timestamp('last_heartbeat_at')->nullable()->after('last_seen_at');
            $table->timestamp('last_cron_at')->nullable()->after('last_heartbeat_at');
            $table->string('cron_php_version', 32)->nullable()->after('last_cron_at');
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table) {
            $table->dropColumn(['last_heartbeat_at', 'last_cron_at', 'cron_php_version']);
        });
    }
};

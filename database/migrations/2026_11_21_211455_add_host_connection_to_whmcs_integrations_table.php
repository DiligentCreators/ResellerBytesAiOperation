<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table): void {
            $table->text('host_connection')->nullable()->after('pending_diagnostic_collect_execution_id');
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table): void {
            $table->dropColumn('host_connection');
        });
    }
};

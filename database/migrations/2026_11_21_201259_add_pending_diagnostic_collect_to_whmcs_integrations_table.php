<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table): void {
            $table->timestamp('pending_diagnostic_collect_at')->nullable();
            $table->uuid('pending_diagnostic_collect_execution_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table): void {
            $table->dropColumn([
                'pending_diagnostic_collect_at',
                'pending_diagnostic_collect_execution_id',
            ]);
        });
    }
};

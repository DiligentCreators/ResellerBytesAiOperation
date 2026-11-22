<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->jsonb('resolved_params')->nullable()->after('target_ids');
        });
    }

    public function down(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->dropColumn('resolved_params');
        });
    }
};

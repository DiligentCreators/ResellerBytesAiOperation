<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->timestamp('agent_picked_up_at')->nullable()->after('approved_at');
            $table->string('late_result_status', 32)->nullable()->after('result');
            $table->timestamp('late_result_at')->nullable()->after('late_result_status');
        });
    }

    public function down(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->dropColumn([
                'agent_picked_up_at',
                'late_result_status',
                'late_result_at',
            ]);
        });
    }
};

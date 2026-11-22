<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->foreignId('rejected_by_user_id')->nullable()->after('approved_by_user_id')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('approval_reason');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('safe_action_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropColumn(['rejection_reason', 'rejected_at']);
        });
    }
};

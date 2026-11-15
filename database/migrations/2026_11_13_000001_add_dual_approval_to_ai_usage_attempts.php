<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table) {
            $table->string('reconciliation_approved_by', 120)->nullable()->after('reconciled_by');
            $table->timestamp('reconciliation_approved_at')->nullable()->after('reconciled_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table) {
            $table->dropColumn(['reconciliation_approved_by', 'reconciliation_approved_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_runs', function (Blueprint $table) {
            $table->string('analysis_model', 80)->nullable();
            $table->string('analysis_prompt_version', 32)->nullable();
            $table->string('analysis_response_id', 80)->nullable();
            $table->text('analysis_summary')->nullable();
            $table->text('no_findings_reason')->nullable();
            $table->timestamp('analysis_started_at')->nullable();
            $table->timestamp('analysis_completed_at')->nullable();
            $table->string('analysis_error_code', 80)->nullable();
            $table->unsignedSmallInteger('analysis_attempts')->default(0);
            $table->jsonb('analysis_usage')->nullable();

            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'received_at']);
            $table->dropColumn([
                'analysis_model',
                'analysis_prompt_version',
                'analysis_response_id',
                'analysis_summary',
                'no_findings_reason',
                'analysis_started_at',
                'analysis_completed_at',
                'analysis_error_code',
                'analysis_attempts',
                'analysis_usage',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_budget_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 12);
            $table->date('period_start');
            $table->bigInteger('limit_micro_usd');
            $table->bigInteger('reserved_micro_usd')->default(0);
            $table->bigInteger('committed_micro_usd')->default(0);
            $table->timestamps();

            $table->unique(['period_type', 'period_start']);
            $table->index(['period_type', 'period_start']);
        });

        Schema::create('ai_usage_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('diagnostic_run_id')->nullable()->constrained('diagnostic_runs')->nullOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('model', 80);
            $table->string('prompt_version', 32)->nullable();
            $table->string('price_card_version', 100);
            $table->unsignedInteger('input_tenths_micro_usd_per_token');
            $table->unsignedInteger('cached_input_tenths_micro_usd_per_token');
            $table->unsignedInteger('output_tenths_micro_usd_per_token');
            $table->date('daily_period_start');
            $table->date('monthly_period_start');
            $table->string('status', 20)->index();
            $table->bigInteger('reserved_micro_usd');
            $table->bigInteger('settled_micro_usd')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->string('provider_response_id', 100)->nullable()->unique();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('reconciled_by', 120)->nullable();
            $table->text('reconciliation_note')->nullable();
            $table->timestamps();

            $table->unique(['diagnostic_run_id', 'attempt_number']);
            $table->index(['status', 'submitted_at']);
            $table->index(['diagnostic_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_attempts');
        Schema::dropIfExists('ai_budget_periods');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->nullable()->after('diagnostic_run_id')
                ->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'submitted_at']);
        });

        Schema::create('ai_tenant_budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('period_type', 12);
            $table->date('period_start');
            $table->bigInteger('limit_micro_usd');
            $table->bigInteger('reserved_micro_usd')->default(0);
            $table->bigInteger('committed_micro_usd')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'period_type', 'period_start'], 'ai_tenant_budget_period_unique');
            $table->index(['tenant_id', 'period_type', 'period_start'], 'ai_tenant_budget_period_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tenant_budget_periods');

        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status', 'submitted_at']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

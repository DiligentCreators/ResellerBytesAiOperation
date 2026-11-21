<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_action_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('integration_id')->constrained('whmcs_integrations')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->nullable()->constrained('diagnostic_findings')->nullOnDelete();
            $table->string('action_key', 64);
            $table->string('status', 32)->default('pending_approval')->index();
            $table->string('risk_level', 16);
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_reason')->nullable();
            $table->boolean('requires_password_confirm')->default(false);
            $table->jsonb('input')->nullable();
            $table->jsonb('result')->nullable();
            $table->jsonb('before_evidence')->nullable();
            $table->jsonb('after_evidence')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['integration_id', 'action_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_action_executions');
    }
};

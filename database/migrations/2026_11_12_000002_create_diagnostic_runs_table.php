<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('whmcs_integrations')->cascadeOnDelete();
            $table->uuid('snapshot_uuid');
            $table->string('schema_version', 24);
            $table->string('addon_version', 40);
            $table->string('status', 24)->default('received')->index();
            $table->timestamp('collected_at');
            $table->timestamp('received_at');
            $table->jsonb('payload');
            $table->char('payload_sha256', 64);
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'snapshot_uuid']);
            $table->index(['integration_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_runs');
    }
};

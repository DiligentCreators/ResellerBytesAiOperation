<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('diagnostic_run_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->string('category', 64);
            $table->string('severity', 16)->index();
            $table->string('title', 180);
            $table->text('summary');
            $table->jsonb('evidence');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->timestamps();

            $table->unique(['diagnostic_run_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_findings');
    }
};

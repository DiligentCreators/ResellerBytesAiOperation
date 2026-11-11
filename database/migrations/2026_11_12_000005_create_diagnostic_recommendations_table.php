<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('diagnostic_finding_id')->constrained()->cascadeOnDelete();
            $table->text('recommendation');
            $table->text('rationale');
            $table->string('risk_level', 16);
            $table->boolean('requires_manual_action')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_recommendations');
    }
};

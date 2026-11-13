<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type', 64);
            $table->string('subject_id', 64);
            $table->string('status', 16)->default('active');
            $table->foreignId('placed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('evidence_reference', 255);
            $table->timestamp('placed_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status'], 'legal_holds_subject_status_idx');
            $table->index(['status', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
    }
};

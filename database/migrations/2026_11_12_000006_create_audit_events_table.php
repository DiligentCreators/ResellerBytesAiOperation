<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 64)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('event_type', 120);
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};

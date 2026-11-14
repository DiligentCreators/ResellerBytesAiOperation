<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 120);
            $table->string('notification_key', 120);
            $table->jsonb('payload');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at'], 'tenant_notification_inbox_lookup');
            $table->unique(['tenant_id', 'user_id', 'event_type', 'notification_key'], 'tenant_notification_deduplication');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notifications');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_alert_states', function (Blueprint $table): void {
            $table->string('code', 96)->primary();
            $table->string('severity', 24);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('delivery_claimed_at')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->json('last_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_alert_states');
    }
};

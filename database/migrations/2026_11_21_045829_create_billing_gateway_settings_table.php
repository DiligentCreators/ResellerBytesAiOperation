<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_gateway_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('gateway')->nullable();
            $table->string('kelviq_mode')->nullable();
            $table->text('kelviq_sandbox_api_key')->nullable();
            $table->text('kelviq_live_api_key')->nullable();
            $table->text('kelviq_sandbox_webhook_secret')->nullable();
            $table->text('kelviq_live_webhook_secret')->nullable();
            $table->boolean('trials_enabled')->default(false);
            $table->boolean('trial_card_required')->default(true);
            $table->boolean('trial_first_time_only')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_gateway_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_request_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('integration_id')->constrained('whmcs_integrations')->cascadeOnDelete();
            $table->string('nonce', 128);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['integration_id', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_request_nonces');
    }
};

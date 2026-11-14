<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('driver', 20)->default('log');
            $table->text('configuration');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_settings');
    }
};

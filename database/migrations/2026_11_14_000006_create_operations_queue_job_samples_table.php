<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_queue_job_samples', function (Blueprint $table): void {
            $table->string('connection_name', 64);
            $table->string('job_id', 128);
            $table->string('queue_name', 128);
            $table->timestamp('available_at')->index();
            $table->timestamps();
            $table->unique(['connection_name', 'job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_queue_job_samples');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('status_code');
            $table->decimal('duration_ms', 12, 3);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_metric_samples');
    }
};

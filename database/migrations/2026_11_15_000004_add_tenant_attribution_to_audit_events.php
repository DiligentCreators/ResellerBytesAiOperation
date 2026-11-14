<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->nullable()->after('actor_id')
                ->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'chain_sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'chain_sequence']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

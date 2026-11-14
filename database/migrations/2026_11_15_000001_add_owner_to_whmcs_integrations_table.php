<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table) {
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->index(['owner_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table) {
            $table->dropIndex(['owner_user_id', 'status']);
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};

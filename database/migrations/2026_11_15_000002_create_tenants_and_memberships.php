<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('status', 20)->default('provisioning')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 24);
            $table->string('status', 24)->default('invited');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'status', 'role']);
        });

        Schema::table('whmcs_integrations', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('owner_user_id')
                ->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
    }
};

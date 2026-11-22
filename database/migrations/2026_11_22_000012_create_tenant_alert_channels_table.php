<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_alert_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('name', 120);
            $table->text('target');
            $table->text('signing_secret')->nullable();
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'tenant_alert_channels_id_tenant_unique');
            $table->index(['tenant_id', 'enabled'], 'tenant_alert_channels_tenant_enabled_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->enablePostgresRls();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON tenant_alert_channels');
            DB::statement('ALTER TABLE tenant_alert_channels DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('tenant_alert_channels');
    }

    private function enablePostgresRls(): void
    {
        DB::statement('ALTER TABLE tenant_alert_channels ENABLE ROW LEVEL SECURITY');
        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenant_alert_channels.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
        DB::statement("CREATE POLICY tenant_isolation_policy ON tenant_alert_channels USING ({$scope}) WITH CHECK ({$scope})");
    }
};

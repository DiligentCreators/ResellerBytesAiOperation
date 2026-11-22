<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_report_sends', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('type', 32);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('sent_at')->nullable();
            $table->json('summary');
            $table->timestamps();

            $table->unique(['id', 'tenant_id'], 'tenant_report_sends_id_tenant_unique');
            $table->unique(['tenant_id', 'type', 'period_start'], 'tenant_report_sends_tenant_type_period_unique');
            $table->index(['tenant_id', 'type', 'sent_at'], 'tenant_report_sends_tenant_type_sent_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->enablePostgresRls();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON tenant_report_sends');
            DB::statement('ALTER TABLE tenant_report_sends DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('tenant_report_sends');
    }

    private function enablePostgresRls(): void
    {
        DB::statement('ALTER TABLE tenant_report_sends ENABLE ROW LEVEL SECURITY');
        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenant_report_sends.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
        DB::statement("CREATE POLICY tenant_isolation_policy ON tenant_report_sends USING ({$scope}) WITH CHECK ({$scope})");
    }
};

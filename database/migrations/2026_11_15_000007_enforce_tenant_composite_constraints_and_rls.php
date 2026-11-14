<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whmcs_integrations', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'integrations_id_tenant_unique');
        });

        Schema::table('diagnostic_runs', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('integration_id');
        });

        DB::statement('UPDATE diagnostic_runs SET tenant_id = (SELECT tenant_id FROM whmcs_integrations WHERE whmcs_integrations.id = diagnostic_runs.integration_id) WHERE EXISTS (SELECT 1 FROM whmcs_integrations WHERE whmcs_integrations.id = diagnostic_runs.integration_id AND whmcs_integrations.tenant_id IS NOT NULL)');

        Schema::table('diagnostic_runs', function (Blueprint $table): void {
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->restrictOnDelete();
            $table->unique(['id', 'tenant_id'], 'diagnostic_runs_id_tenant_unique');
            $table->foreign(['integration_id', 'tenant_id'], 'diagnostic_runs_integration_tenant_fk')
                ->references(['id', 'tenant_id'])->on('whmcs_integrations')->cascadeOnDelete();
            $table->index(['tenant_id', 'received_at'], 'diagnostic_runs_tenant_received_idx');
        });

        Schema::table('diagnostic_findings', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('diagnostic_run_id');
        });

        DB::statement('UPDATE diagnostic_findings SET tenant_id = (SELECT tenant_id FROM diagnostic_runs WHERE diagnostic_runs.id = diagnostic_findings.diagnostic_run_id) WHERE EXISTS (SELECT 1 FROM diagnostic_runs WHERE diagnostic_runs.id = diagnostic_findings.diagnostic_run_id AND diagnostic_runs.tenant_id IS NOT NULL)');

        Schema::table('diagnostic_findings', function (Blueprint $table): void {
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->restrictOnDelete();
            $table->unique(['id', 'tenant_id'], 'diagnostic_findings_id_tenant_unique');
            $table->foreign(['diagnostic_run_id', 'tenant_id'], 'diagnostic_findings_run_tenant_fk')
                ->references(['id', 'tenant_id'])->on('diagnostic_runs')->cascadeOnDelete();
            $table->index(['tenant_id', 'status'], 'diagnostic_findings_tenant_status_idx');
        });

        Schema::table('diagnostic_recommendations', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('diagnostic_finding_id');
        });

        DB::statement('UPDATE diagnostic_recommendations SET tenant_id = (SELECT tenant_id FROM diagnostic_findings WHERE diagnostic_findings.id = diagnostic_recommendations.diagnostic_finding_id) WHERE EXISTS (SELECT 1 FROM diagnostic_findings WHERE diagnostic_findings.id = diagnostic_recommendations.diagnostic_finding_id AND diagnostic_findings.tenant_id IS NOT NULL)');

        Schema::table('diagnostic_recommendations', function (Blueprint $table): void {
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['diagnostic_finding_id', 'tenant_id'], 'diagnostic_recommendations_finding_tenant_fk')
                ->references(['id', 'tenant_id'])->on('diagnostic_findings')->cascadeOnDelete();
            $table->index(['tenant_id', 'diagnostic_finding_id'], 'diagnostic_recommendations_tenant_finding_idx');
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'tenant_subscriptions_id_tenant_unique');
        });

        Schema::table('tenant_billing_invoices', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'tenant_billing_invoices_id_tenant_unique');
            $table->foreign(['tenant_subscription_id', 'tenant_id'], 'tenant_invoices_subscription_tenant_fk')
                ->references(['id', 'tenant_id'])->on('tenant_subscriptions')->restrictOnDelete();
        });

        Schema::table('tenant_billing_payments', function (Blueprint $table): void {
            $table->foreign(['tenant_billing_invoice_id', 'tenant_id'], 'tenant_payments_invoice_tenant_fk')
                ->references(['id', 'tenant_id'])->on('tenant_billing_invoices')->restrictOnDelete();
        });

        Schema::table('tenant_notifications', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'user_id'], 'tenant_notifications_membership_fk')
                ->references(['tenant_id', 'user_id'])->on('tenant_memberships')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->enablePostgresRls();
        }
    }

    private function enablePostgresRls(): void
    {
        $tenantTables = [
            'tenant_memberships',
            'diagnostic_runs',
            'diagnostic_findings',
            'diagnostic_recommendations',
            'ai_usage_attempts',
            'ai_tenant_budget_periods',
            'audit_events',
            'tenant_subscriptions',
            'tenant_billing_invoices',
            'tenant_billing_payments',
            'tenant_notifications',
        ];

        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenants.id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        DB::statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_policy ON tenants USING (id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership}) WITH CHECK (id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})");

        DB::statement('ALTER TABLE whmcs_integrations ENABLE ROW LEVEL SECURITY');
        $integrationMembership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = whmcs_integrations.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $integrationScope = "(tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$integrationMembership})";
        DB::statement("CREATE POLICY tenant_integration_select ON whmcs_integrations FOR SELECT USING ({$integrationScope} OR id = NULLIF(current_setting('app.integration_lookup_id', true), '')::uuid)");
        DB::statement("CREATE POLICY tenant_integration_write ON whmcs_integrations FOR ALL USING ({$integrationScope}) WITH CHECK ({$integrationScope})");

        DB::statement('ALTER TABLE tenant_memberships ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_policy ON tenant_memberships USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint)");

        foreach ($tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = {$table}.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
            $scope = "(tenant_id IS NULL OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
            DB::statement("CREATE POLICY tenant_isolation_policy ON {$table} USING ({$scope}) WITH CHECK ({$scope})");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach ([
                'tenant_notifications', 'tenant_billing_payments', 'whmcs_integrations', 'tenant_billing_invoices',
                'tenant_subscriptions', 'audit_events', 'ai_tenant_budget_periods',
                'ai_usage_attempts', 'diagnostic_recommendations', 'diagnostic_findings',
                'diagnostic_runs', 'tenant_memberships',
            ] as $table) {
                if ($table === 'whmcs_integrations') {
                    DB::statement('DROP POLICY IF EXISTS tenant_integration_select ON whmcs_integrations');
                    DB::statement('DROP POLICY IF EXISTS tenant_integration_write ON whmcs_integrations');
                } else {
                    DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$table}");
                }
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON tenants');
            DB::statement('ALTER TABLE tenants DISABLE ROW LEVEL SECURITY');
        }

        Schema::table('tenant_notifications', fn (Blueprint $table) => $table->dropForeign('tenant_notifications_membership_fk'));
        Schema::table('tenant_billing_payments', fn (Blueprint $table) => $table->dropForeign('tenant_payments_invoice_tenant_fk'));
        Schema::table('tenant_billing_invoices', function (Blueprint $table): void {
            $table->dropForeign('tenant_invoices_subscription_tenant_fk');
            $table->dropUnique('tenant_billing_invoices_id_tenant_unique');
        });
        Schema::table('tenant_subscriptions', fn (Blueprint $table) => $table->dropUnique('tenant_subscriptions_id_tenant_unique'));

        Schema::table('diagnostic_recommendations', function (Blueprint $table): void {
            $table->dropForeign('diagnostic_recommendations_finding_tenant_fk');
            $table->dropForeign(['tenant_id']);
            $table->dropIndex('diagnostic_recommendations_tenant_finding_idx');
            $table->dropColumn('tenant_id');
        });
        Schema::table('diagnostic_findings', function (Blueprint $table): void {
            $table->dropForeign('diagnostic_findings_run_tenant_fk');
            $table->dropForeign(['tenant_id']);
            $table->dropUnique('diagnostic_findings_id_tenant_unique');
            $table->dropIndex('diagnostic_findings_tenant_status_idx');
            $table->dropColumn('tenant_id');
        });
        Schema::table('diagnostic_runs', function (Blueprint $table): void {
            $table->dropForeign('diagnostic_runs_integration_tenant_fk');
            $table->dropForeign(['tenant_id']);
            $table->dropUnique('diagnostic_runs_id_tenant_unique');
            $table->dropIndex('diagnostic_runs_tenant_received_idx');
            $table->dropColumn('tenant_id');
        });
        Schema::table('whmcs_integrations', fn (Blueprint $table) => $table->dropUnique('integrations_id_tenant_unique'));
    }
};

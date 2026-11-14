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

        $this->createTenantConsistencyTriggers();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->enablePostgresRls();
        }
    }

    private function createTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION rb_check_diagnostic_run_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    SELECT tenant_id INTO expected_tenant FROM whmcs_integrations WHERE id = NEW.integration_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'diagnostic_run_tenant_mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE FUNCTION rb_check_diagnostic_finding_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    SELECT tenant_id INTO expected_tenant FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'diagnostic_finding_tenant_mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE FUNCTION rb_check_diagnostic_recommendation_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    SELECT tenant_id INTO expected_tenant FROM diagnostic_findings WHERE id = NEW.diagnostic_finding_id;
    IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
        RAISE EXCEPTION 'diagnostic_recommendation_tenant_mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE FUNCTION rb_check_ai_usage_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    IF NEW.diagnostic_run_id IS NOT NULL THEN
        SELECT tenant_id INTO expected_tenant FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'ai_usage_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE FUNCTION rb_check_audit_event_tenant() RETURNS trigger LANGUAGE plpgsql AS $rb$
DECLARE expected_tenant uuid;
BEGIN
    IF NEW.subject_type = 'tenant' THEN
        IF NEW.tenant_id::text IS DISTINCT FROM NEW.subject_id THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subject_type = 'diagnostic_run' THEN
        SELECT tenant_id INTO expected_tenant FROM diagnostic_runs WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subject_type = 'diagnostic_finding' THEN
        SELECT tenant_id INTO expected_tenant FROM diagnostic_findings WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subject_type = 'diagnostic_recommendation' THEN
        SELECT tenant_id INTO expected_tenant FROM diagnostic_recommendations WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subject_type = 'ai_usage_attempt' THEN
        SELECT tenant_id INTO expected_tenant FROM ai_usage_attempts WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subject_type = 'tenant_billing_payment' THEN
        SELECT tenant_id INTO expected_tenant FROM tenant_billing_payments WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subject_type = 'tenant_billing_invoice' THEN
        SELECT tenant_id INTO expected_tenant FROM tenant_billing_invoices WHERE id::text = NEW.subject_id;
        IF NEW.tenant_id IS DISTINCT FROM expected_tenant THEN
            RAISE EXCEPTION 'audit_event_tenant_mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$rb$;
CREATE TRIGGER rb_diagnostic_run_tenant_guard BEFORE INSERT OR UPDATE OF integration_id, tenant_id ON diagnostic_runs FOR EACH ROW EXECUTE FUNCTION rb_check_diagnostic_run_tenant();
CREATE TRIGGER rb_diagnostic_finding_tenant_guard BEFORE INSERT OR UPDATE OF diagnostic_run_id, tenant_id ON diagnostic_findings FOR EACH ROW EXECUTE FUNCTION rb_check_diagnostic_finding_tenant();
CREATE TRIGGER rb_diagnostic_recommendation_tenant_guard BEFORE INSERT OR UPDATE OF diagnostic_finding_id, tenant_id ON diagnostic_recommendations FOR EACH ROW EXECUTE FUNCTION rb_check_diagnostic_recommendation_tenant();
CREATE TRIGGER rb_ai_usage_tenant_guard BEFORE INSERT OR UPDATE OF diagnostic_run_id, tenant_id ON ai_usage_attempts FOR EACH ROW EXECUTE FUNCTION rb_check_ai_usage_tenant();
CREATE TRIGGER rb_audit_event_tenant_guard BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION rb_check_audit_event_tenant();
SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER rb_diagnostic_run_tenant_guard_insert BEFORE INSERT ON diagnostic_runs
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM whmcs_integrations WHERE id = NEW.integration_id)
BEGIN SELECT RAISE(ABORT, 'diagnostic_run_tenant_mismatch'); END;
CREATE TRIGGER rb_diagnostic_run_tenant_guard_update BEFORE UPDATE OF integration_id, tenant_id ON diagnostic_runs
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM whmcs_integrations WHERE id = NEW.integration_id)
BEGIN SELECT RAISE(ABORT, 'diagnostic_run_tenant_mismatch'); END;
CREATE TRIGGER rb_diagnostic_finding_tenant_guard_insert BEFORE INSERT ON diagnostic_findings
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id)
BEGIN SELECT RAISE(ABORT, 'diagnostic_finding_tenant_mismatch'); END;
CREATE TRIGGER rb_diagnostic_finding_tenant_guard_update BEFORE UPDATE OF diagnostic_run_id, tenant_id ON diagnostic_findings
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id)
BEGIN SELECT RAISE(ABORT, 'diagnostic_finding_tenant_mismatch'); END;
CREATE TRIGGER rb_ai_usage_tenant_guard_insert BEFORE INSERT ON ai_usage_attempts
WHEN NEW.diagnostic_run_id IS NOT NULL AND NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id)
BEGIN SELECT RAISE(ABORT, 'ai_usage_tenant_mismatch'); END;
CREATE TRIGGER rb_ai_usage_tenant_guard_update BEFORE UPDATE OF diagnostic_run_id, tenant_id ON ai_usage_attempts
WHEN NEW.diagnostic_run_id IS NOT NULL AND NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_runs WHERE id = NEW.diagnostic_run_id)
BEGIN SELECT RAISE(ABORT, 'ai_usage_tenant_mismatch'); END;
CREATE TRIGGER rb_audit_event_tenant_guard BEFORE INSERT ON audit_events
WHEN (NEW.subject_type = 'tenant' AND NEW.tenant_id IS NOT NEW.subject_id)
 OR (NEW.subject_type = 'diagnostic_run' AND NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_runs WHERE id = NEW.subject_id))
 OR (NEW.subject_type = 'diagnostic_finding' AND NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_findings WHERE id = NEW.subject_id))
 OR (NEW.subject_type = 'diagnostic_recommendation' AND NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_recommendations WHERE id = NEW.subject_id))
 OR (NEW.subject_type = 'ai_usage_attempt' AND NEW.tenant_id IS NOT (SELECT tenant_id FROM ai_usage_attempts WHERE id = NEW.subject_id))
 OR (NEW.subject_type = 'tenant_billing_payment' AND NEW.tenant_id IS NOT (SELECT tenant_id FROM tenant_billing_payments WHERE id = CAST(NEW.subject_id AS INTEGER)))
 OR (NEW.subject_type = 'tenant_billing_invoice' AND NEW.tenant_id IS NOT (SELECT tenant_id FROM tenant_billing_invoices WHERE id = CAST(NEW.subject_id AS INTEGER)))
BEGIN SELECT RAISE(ABORT, 'audit_event_tenant_mismatch'); END;
CREATE TRIGGER rb_diagnostic_recommendation_tenant_guard_insert BEFORE INSERT ON diagnostic_recommendations
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_findings WHERE id = NEW.diagnostic_finding_id)
BEGIN SELECT RAISE(ABORT, 'diagnostic_recommendation_tenant_mismatch'); END;
CREATE TRIGGER rb_diagnostic_recommendation_tenant_guard_update BEFORE UPDATE OF diagnostic_finding_id, tenant_id ON diagnostic_recommendations
WHEN NEW.tenant_id IS NOT (SELECT tenant_id FROM diagnostic_findings WHERE id = NEW.diagnostic_finding_id)
BEGIN SELECT RAISE(ABORT, 'diagnostic_recommendation_tenant_mismatch'); END;
SQL);
        }
    }

    private function dropTenantConsistencyTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach ([
                'rb_diagnostic_run_tenant_guard' => 'diagnostic_runs',
                'rb_diagnostic_finding_tenant_guard' => 'diagnostic_findings',
                'rb_diagnostic_recommendation_tenant_guard' => 'diagnostic_recommendations',
                'rb_ai_usage_tenant_guard' => 'ai_usage_attempts',
                'rb_audit_event_tenant_guard' => 'audit_events',
            ] as $trigger => $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
            }

            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_diagnostic_run_tenant()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_diagnostic_finding_tenant()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_diagnostic_recommendation_tenant()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_ai_usage_tenant()');
            DB::unprepared('DROP FUNCTION IF EXISTS rb_check_audit_event_tenant()');
        } elseif ($driver === 'sqlite') {
            foreach ([
                'rb_diagnostic_run_tenant_guard_insert',
                'rb_diagnostic_run_tenant_guard_update',
                'rb_diagnostic_finding_tenant_guard_insert',
                'rb_diagnostic_finding_tenant_guard_update',
                'rb_diagnostic_recommendation_tenant_guard_insert',
                'rb_diagnostic_recommendation_tenant_guard_update',
                'rb_ai_usage_tenant_guard_insert',
                'rb_ai_usage_tenant_guard_update',
                'rb_audit_event_tenant_guard',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    private function enablePostgresRls(): void
    {
        $tenantTables = [
            'diagnostic_runs',
            'diagnostic_findings',
            'diagnostic_recommendations',
            'ai_usage_attempts',
            'ai_tenant_budget_periods',
            'audit_events',
            'tenant_subscriptions',
            'tenant_billing_invoices',
            'tenant_billing_payments',
        ];

        $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenants.id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        DB::statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_policy ON tenants USING (id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership}) WITH CHECK (id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})");

        DB::statement('ALTER TABLE whmcs_integrations ENABLE ROW LEVEL SECURITY');
        $integrationMembership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = whmcs_integrations.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
        $integrationScope = "((tenant_id IS NULL AND owner_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint) OR tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$integrationMembership} OR id = NULLIF(current_setting('app.integration_lookup_id', true), '')::uuid)";
        DB::statement("CREATE POLICY tenant_integration_select ON whmcs_integrations FOR SELECT USING ({$integrationScope} OR id = NULLIF(current_setting('app.integration_lookup_id', true), '')::uuid)");
        DB::statement("CREATE POLICY tenant_integration_write ON whmcs_integrations FOR ALL USING ({$integrationScope}) WITH CHECK ({$integrationScope})");

        DB::statement('ALTER TABLE tenant_memberships ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation_policy ON tenant_memberships USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint)");

        foreach ($tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = {$table}.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
            $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";

            if ($table === 'diagnostic_runs') {
                $legacyOwner = "EXISTS (SELECT 1 FROM whmcs_integrations AS rls_integration WHERE rls_integration.id = diagnostic_runs.integration_id AND (rls_integration.owner_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint OR rls_integration.id::text = NULLIF(current_setting('app.integration_lookup_id', true), '')))";
                $scope = "({$scope} OR (tenant_id IS NULL AND {$legacyOwner}))";
            } elseif ($table === 'diagnostic_findings') {
                $legacyOwner = "EXISTS (SELECT 1 FROM diagnostic_runs AS rls_run INNER JOIN whmcs_integrations AS rls_integration ON rls_integration.id = rls_run.integration_id WHERE rls_run.id = diagnostic_findings.diagnostic_run_id AND rls_integration.owner_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint)";
                $scope = "({$scope} OR (tenant_id IS NULL AND {$legacyOwner}))";
            } elseif ($table === 'diagnostic_recommendations') {
                $legacyOwner = "EXISTS (SELECT 1 FROM diagnostic_findings AS rls_finding INNER JOIN diagnostic_runs AS rls_run ON rls_run.id = rls_finding.diagnostic_run_id INNER JOIN whmcs_integrations AS rls_integration ON rls_integration.id = rls_run.integration_id WHERE rls_finding.id = diagnostic_recommendations.diagnostic_finding_id AND rls_integration.owner_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint)";
                $scope = "({$scope} OR (tenant_id IS NULL AND {$legacyOwner}))";
            } elseif ($table === 'ai_usage_attempts') {
                $legacyOwner = "EXISTS (SELECT 1 FROM diagnostic_runs AS rls_run INNER JOIN whmcs_integrations AS rls_integration ON rls_integration.id = rls_run.integration_id WHERE rls_run.id = ai_usage_attempts.diagnostic_run_id AND rls_integration.owner_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint)";
                $scope = "({$scope} OR (tenant_id IS NULL AND {$legacyOwner}))";
            } elseif ($table === 'audit_events') {
                $legacyActor = "((audit_events.actor_type = 'user' AND audit_events.actor_id = NULLIF(current_setting('app.current_user_id', true), '')) OR (audit_events.actor_type = 'integration' AND (audit_events.actor_id = NULLIF(current_setting('app.integration_lookup_id', true), '') OR EXISTS (SELECT 1 FROM whmcs_integrations AS rls_integration WHERE rls_integration.id::text = audit_events.actor_id AND rls_integration.owner_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint))))";
                $scope = "({$scope} OR (tenant_id IS NULL AND {$legacyActor}))";
            }

            DB::statement("CREATE POLICY tenant_isolation_policy ON {$table} USING ({$scope}) WITH CHECK ({$scope})");
        }

        DB::statement('ALTER TABLE tenant_notifications ENABLE ROW LEVEL SECURITY');
        $notificationMembership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = tenant_notifications.tenant_id AND rls_membership.user_id = tenant_notifications.user_id AND rls_membership.status = 'active')";
        DB::statement("CREATE POLICY tenant_notifications_select ON tenant_notifications FOR SELECT USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND {$notificationMembership})");
        DB::statement("CREATE POLICY tenant_notifications_insert ON tenant_notifications FOR INSERT WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid AND {$notificationMembership})");
        DB::statement("CREATE POLICY tenant_notifications_update ON tenant_notifications FOR UPDATE USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND {$notificationMembership}) WITH CHECK (user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND {$notificationMembership})");
    }

    public function down(): void
    {
        $this->dropTenantConsistencyTriggers();

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach ([
                'tenant_notifications', 'tenant_billing_payments', 'whmcs_integrations', 'tenant_billing_invoices',
                'tenant_subscriptions', 'audit_events', 'ai_tenant_budget_periods',
                'ai_usage_attempts', 'diagnostic_recommendations', 'diagnostic_findings',
                'diagnostic_runs', 'tenant_memberships',
            ] as $table) {
                if ($table === 'tenant_notifications') {
                    DB::statement('DROP POLICY IF EXISTS tenant_notifications_select ON tenant_notifications');
                    DB::statement('DROP POLICY IF EXISTS tenant_notifications_insert ON tenant_notifications');
                    DB::statement('DROP POLICY IF EXISTS tenant_notifications_update ON tenant_notifications');
                } elseif ($table === 'whmcs_integrations') {
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

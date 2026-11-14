<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->string('event_type', 120);
            $table->string('aggregate_type', 60);
            $table->string('aggregate_id', 120);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key'], 'tenant_billing_outbox_idempotency_unique');
            $table->unique(['id', 'tenant_id'], 'tenant_billing_outbox_id_tenant_unique');
            $table->index(['tenant_id', 'status', 'available_at'], 'tenant_billing_outbox_dispatch_idx');
        });

        Schema::create('tenant_billing_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account_id', 160)->nullable();
            $table->string('provider_event_id', 180);
            $table->string('event_type', 120);
            $table->char('payload_sha256', 64);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('received_at');
            $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'provider', 'provider_event_id'], 'tenant_billing_webhook_idempotency_unique');
            $table->unique(['id', 'tenant_id'], 'tenant_billing_webhook_id_tenant_unique');
            $table->index(['tenant_id', 'status', 'available_at'], 'tenant_billing_webhook_dispatch_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['tenant_billing_outbox', 'tenant_billing_webhook_events'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                $membership = "EXISTS (SELECT 1 FROM tenant_memberships AS rls_membership WHERE rls_membership.tenant_id = {$table}.tenant_id AND rls_membership.user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint AND rls_membership.status = 'active')";
                $scope = "(tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid OR {$membership})";
                DB::statement("CREATE POLICY tenant_isolation_policy ON {$table} USING ({$scope}) WITH CHECK ({$scope})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_webhook_events');
        Schema::dropIfExists('tenant_billing_outbox');
    }
};

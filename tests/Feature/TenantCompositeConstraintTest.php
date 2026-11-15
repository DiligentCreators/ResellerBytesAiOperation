<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('rejects cross-tenant diagnostic findings at the database boundary', function () {
    $tenantA = Tenant::create(['name' => 'Constraint Tenant A', 'slug' => 'constraint-tenant-a', 'status' => Tenant::STATUS_ACTIVE]);
    $tenantB = Tenant::create(['name' => 'Constraint Tenant B', 'slug' => 'constraint-tenant-b', 'status' => Tenant::STATUS_ACTIVE]);
    $owner = User::factory()->create();

    $integration = \App\Models\WhmcsIntegration::create([
        'tenant_id' => $tenantA->id,
        'owner_user_id' => $owner->id,
        'name' => 'Tenant A Integration',
        'status' => 'active',
        'secret' => 'test-integration-secret',
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('diagnostic_runs')->insert([
        'id' => (string) Str::uuid(),
        'integration_id' => $integration->id,
        'tenant_id' => null,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => json_encode(['source' => 'test']),
        'payload_sha256' => hash('sha256', 'legacy-null-bypass'),
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    $runId = (string) Str::uuid();
    DB::table('diagnostic_runs')->insert([
        'id' => $runId,
        'integration_id' => $integration->id,
        'tenant_id' => $tenantA->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => json_encode(['source' => 'test']),
        'payload_sha256' => hash('sha256', 'test'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('diagnostic_findings')->insert([
        'id' => (string) Str::uuid(),
        'diagnostic_run_id' => $runId,
        'tenant_id' => null,
        'fingerprint' => hash('sha256', 'null-tenant-bypass'),
        'category' => 'security',
        'severity' => 'high',
        'title' => 'Null tenant child',
        'summary' => 'A tenant child cannot omit tenant attribution.',
        'evidence' => [],
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('diagnostic_findings')->insert([
        'id' => (string) Str::uuid(),
        'diagnostic_run_id' => $runId,
        'tenant_id' => $tenantB->id,
        'fingerprint' => hash('sha256', 'cross-tenant'),
        'category' => 'security',
        'severity' => 'high',
        'title' => 'Cross-tenant row',
        'summary' => 'Must be rejected by the composite foreign key.',
        'evidence' => [],
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('rejects notification recipients without a membership in the same tenant', function () {
    $tenantA = Tenant::create(['name' => 'Notification Tenant A', 'slug' => 'notification-tenant-a', 'status' => Tenant::STATUS_ACTIVE]);
    $tenantB = Tenant::create(['name' => 'Notification Tenant B', 'slug' => 'notification-tenant-b', 'status' => Tenant::STATUS_ACTIVE]);
    $user = User::factory()->create();

    $tenantA->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('tenant_notifications')->insert([
        'tenant_id' => $tenantB->id,
        'user_id' => $user->id,
        'event_type' => 'diagnostics.run.completed',
        'notification_key' => 'run:cross-tenant:001',
        'payload' => json_encode(['run_id' => 'x']),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);
});


it('rejects invoices and payments linked across tenant billing boundaries', function () {
    $tenantA = Tenant::create(['name' => 'Billing Tenant A', 'slug' => 'billing-tenant-a', 'status' => Tenant::STATUS_ACTIVE]);
    $tenantB = Tenant::create(['name' => 'Billing Tenant B', 'slug' => 'billing-tenant-b', 'status' => Tenant::STATUS_ACTIVE]);

    $subscriptionId = DB::table('tenant_subscriptions')->insertGetId([
        'tenant_id' => $tenantB->id,
        'plan_code' => 'unpriced-test-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => json_encode([]),
        'current_period_starts_at' => now(),
        'current_period_ends_at' => now()->addMonth(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('tenant_billing_invoices')->insert([
        'tenant_id' => $tenantA->id,
        'tenant_subscription_id' => $subscriptionId,
        'invoice_number' => 'CROSS-TENANT-SUBSCRIPTION-001',
        'status' => 'open',
        'currency' => 'USD',
        'amount_due_minor' => 100,
        'amount_paid_minor' => 0,
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    $invoiceId = DB::table('tenant_billing_invoices')->insertGetId([
        'tenant_id' => $tenantA->id,
        'tenant_subscription_id' => null,
        'invoice_number' => 'TENANT-A-INVOICE-001',
        'status' => 'open',
        'currency' => 'USD',
        'amount_due_minor' => 100,
        'amount_paid_minor' => 0,
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('tenant_billing_payments')->insert([
        'tenant_id' => $tenantB->id,
        'tenant_billing_invoice_id' => $invoiceId,
        'collection_mode' => 'manual',
        'idempotency_key' => 'cross-tenant-payment-001',
        'status' => 'confirmed',
        'currency' => 'USD',
        'amount_minor' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});


it('atomically assigns a legacy integration and its diagnostic descendants to a tenant', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Backfill Tenant', 'slug' => 'backfill-tenant', 'status' => Tenant::STATUS_ACTIVE]);
    $integration = \App\Models\WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'name' => 'Legacy integration for tenant backfill',
        'status' => 'active',
        'secret' => 'legacy-backfill-secret',
    ]);
    $run = \App\Models\DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['source' => 'legacy'],
        'payload_sha256' => hash('sha256', 'legacy'),
    ]);
    $finding = \App\Models\DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'fingerprint' => hash('sha256', 'legacy-finding'),
        'category' => 'security',
        'severity' => 'low',
        'title' => 'Legacy finding',
        'summary' => 'Legacy record.',
        'evidence' => json_encode([]),
        'status' => 'open',
    ]);
    $recommendation = \App\Models\DiagnosticRecommendation::create([
        'diagnostic_finding_id' => $finding->id,
        'recommendation' => 'Review legacy configuration.',
        'rationale' => 'Tenant attribution backfill fixture.',
        'risk_level' => 'low',
        'requires_manual_action' => true,
    ]);

    expect(Artisan::call('whmcs:integration:assign-tenant', [
        'integration' => $integration->id,
        'tenant' => $tenant->id,
    ]))->toBe(0);

    expect($integration->fresh()->tenant_id)->toBe($tenant->id)
        ->and($run->fresh()->tenant_id)->toBe($tenant->id)
        ->and($finding->fresh()->tenant_id)->toBe($tenant->id)
        ->and($recommendation->fresh()->tenant_id)->toBe($tenant->id)
        ->and(DB::table('audit_events')->where('event_type', 'whmcs_integration.tenant_assigned')->where('subject_id', $integration->id)->where('tenant_id', $tenant->id)->exists())->toBeTrue();

    expect(Artisan::call('whmcs:integration:assign-tenant', [
        'integration' => $integration->id,
        'tenant' => $tenant->id,
    ]))->toBe(1);
});


it('enforces subscription integration and diagnostic-period quotas from the entitlement snapshot', function () {
    $tenant = Tenant::create([
        'name' => 'Quota Workspace',
        'slug' => 'quota-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    \App\Models\TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'quota-test-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => [
            'limits' => [
                'integrations' => 1,
                'diagnostics_per_period' => 1,
            ],
        ],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $limits = app(\App\Services\Tenancy\TenantResourceLimitService::class);
    $limits->requireIntegrationCapacity($tenant->id);

    \App\Models\WhmcsIntegration::create([
        'tenant_id' => $tenant->id,
        'name' => 'Quota Integration',
        'status' => 'active',
        'secret' => 'quota-integration-secret',
    ]);

    expect(fn () => $limits->requireIntegrationCapacity($tenant->id))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $run = \App\Models\DiagnosticRun::create([
        'integration_id' => $tenant->integrations()->firstOrFail()->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['source' => 'quota-test'],
        'payload_sha256' => hash('sha256', 'quota-test'),
    ]);

    expect(fn () => $limits->requireDiagnosticCapacity($tenant->id))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect(fn () => \App\Models\DiagnosticRun::create([
        'integration_id' => $run->integration_id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['source' => 'quota-test-overage'],
        'payload_sha256' => hash('sha256', 'quota-test-overage'),
    ]))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

    expect(fn () => DB::table('diagnostic_findings')->insert([
        'id' => (string) Str::uuid(),
        'diagnostic_run_id' => $runId,
        'tenant_id' => $tenantB->id,
        'fingerprint' => hash('sha256', 'cross-tenant'),
        'category' => 'security',
        'severity' => 'high',
        'title' => 'Cross-tenant row',
        'summary' => 'Must be rejected by the composite foreign key.',
        'evidence' => json_encode([]),
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
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

    expect(fn () => DB::table('tenant_notifications')->insert([
        'tenant_id' => $tenantB->id,
        'user_id' => $user->id,
        'event_type' => 'diagnostics.run.completed',
        'notification_key' => 'run:cross-tenant:001',
        'payload' => json_encode(['run_id' => 'x']),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

<?php

use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsHealthSnapshot;
use App\Models\WhmcsIntegration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('nulls diagnostic_run_id on health snapshots when the run is deleted without clearing tenant_id', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Snapshot FK Tenant',
        'slug' => 'snapshot-fk-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Snapshot FK WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
        'last_seen_at' => now()->subHour(),
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => hash('sha256', 'snapshot-fk'),
        'analysis_summary' => 'ok',
    ]);

    $snapshot = WhmcsHealthSnapshot::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'diagnostic_run_id' => $run->id,
        'captured_at' => now('UTC'),
        'captured_on' => now('UTC')->toDateString(),
        'score' => 90,
        'critical_count' => 0,
        'warning_count' => 0,
        'info_count' => 0,
        'metrics' => ['heartbeat_state' => 'ok'],
    ]);

    $run->delete();

    $snapshot->refresh();

    expect($snapshot->diagnostic_run_id)->toBeNull()
        ->and($snapshot->tenant_id)->toBe($tenant->id)
        ->and($snapshot->integration_id)->toBe($integration->id);
});

it('recreates whmcs_health_snapshots when an empty orphan table already exists', function () {
    Schema::dropIfExists('whmcs_health_snapshots');

    Schema::create('whmcs_health_snapshots', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
    });

    expect(DB::table('whmcs_health_snapshots')->count())->toBe(0);

    DB::table('migrations')
        ->where('migration', '2026_11_22_000009_create_whmcs_health_snapshots_table')
        ->delete();

    expect(Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_11_22_000009_create_whmcs_health_snapshots_table.php',
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::hasTable('whmcs_health_snapshots'))->toBeTrue()
        ->and(Schema::hasColumns('whmcs_health_snapshots', [
            'id',
            'tenant_id',
            'integration_id',
            'diagnostic_run_id',
            'captured_at',
            'captured_on',
            'score',
            'metrics',
        ]))->toBeTrue();

    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Orphan Recover Tenant',
        'slug' => 'orphan-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Orphan WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
        'last_seen_at' => now()->subHour(),
    ]);

    $snapshot = WhmcsHealthSnapshot::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'diagnostic_run_id' => null,
        'captured_at' => now('UTC'),
        'captured_on' => now('UTC')->toDateString(),
        'score' => 100,
        'critical_count' => 0,
        'warning_count' => 0,
        'info_count' => 0,
        'metrics' => ['heartbeat_state' => 'ok'],
    ]);

    expect($snapshot->exists)->toBeTrue();
});

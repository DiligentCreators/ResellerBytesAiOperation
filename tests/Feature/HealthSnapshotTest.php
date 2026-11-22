<?php

use App\Events\InstallationHeartbeatStateChanged;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsHealthSnapshot;
use App\Models\WhmcsIntegration;
use App\Services\Health\HealthSnapshotRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

function createHealthWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Health Workspace',
        'slug' => 'health-'.uniqid(),
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
        'name' => 'Health WHMCS',
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
        'payload' => [
            'system' => ['whmcs_version' => '8.13.7'],
            'whmcs' => [
                'billing' => [
                    'overdue_unpaid_count' => 2,
                    'recovered_paid_7d_total' => '150.00',
                ],
                'services' => [
                    'suspended_with_paid_invoices' => ['count' => 1, 'service_ids' => [10]],
                ],
            ],
            'host' => [
                'disk_free_percent' => 42.5,
            ],
        ],
        'payload_sha256' => hash('sha256', 'health-payload'),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

it('upserts a daily health snapshot roll-up for an integration', function () {
    Carbon::setTestNow('2026-11-22 10:00:00');
    $workspace = createHealthWorkspace();
    $recorder = app(HealthSnapshotRecorder::class);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'critical-one'),
        'issue_key' => 'service.suspended_but_paid',
        'source' => 'rule',
        'affected_count' => 1,
        'fix_mode' => 'one_click',
        'category' => 'services',
        'severity' => 'critical',
        'title' => 'Suspended but paid',
        'summary' => 'test',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    $first = $recorder->recomputeForIntegration($workspace['integration'], $workspace['run']);
    expect($first)->not->toBeNull()
        ->and($first->score)->toBe(75)
        ->and($first->critical_count)->toBe(1)
        ->and($first->metrics['overdue_unpaid_count'])->toBe(2)
        ->and($first->metrics['disk_free_percent'])->toBe(42.5);

    Carbon::setTestNow('2026-11-22 18:00:00');
    $workspace['integration']->forceFill([
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_DOWN,
    ])->save();

    $second = $recorder->recomputeForIntegration($workspace['integration']->fresh(), $workspace['run']);

    expect(WhmcsHealthSnapshot::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->score)->toBe(40)
        ->and($second->captured_on->toDateString())->toBe('2026-11-22');

    Carbon::setTestNow();
});

it('recomputes health when heartbeat state changes', function () {
    Carbon::setTestNow('2026-11-22 12:00:00');
    $workspace = createHealthWorkspace();

    // HeartbeatStateService persists the new state before dispatching the event.
    $workspace['integration']->forceFill([
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        'heartbeat_state_changed_at' => now('UTC'),
    ])->save();

    event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        now('UTC'),
    ));

    $snapshot = WhmcsHealthSnapshot::query()
        ->where('integration_id', $workspace['integration']->id)
        ->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->score)->toBe(40)
        ->and($snapshot->metrics['heartbeat_state'])->toBe(WhmcsIntegration::HEARTBEAT_STATE_DOWN);

    Carbon::setTestNow();
});

it('isolates health snapshots by tenant on the dashboard', function () {
    $a = createHealthWorkspace();
    $b = createHealthWorkspace();

    app(HealthSnapshotRecorder::class)->recomputeForIntegration($a['integration'], $a['run']);
    app(HealthSnapshotRecorder::class)->recomputeForIntegration($b['integration'], $b['run']);

    $this->actingAs($a['owner'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('health.score')
            ->has('health.sparkline')
            ->has('needsYouNow')
            ->has('timeSaved')
            ->has('installations'));

    expect(
        WhmcsHealthSnapshot::query()->where('tenant_id', $a['tenant']->id)->count()
    )->toBe(1)
        ->and(
            WhmcsHealthSnapshot::query()->where('tenant_id', $b['tenant']->id)->count()
        )->toBe(1);
});

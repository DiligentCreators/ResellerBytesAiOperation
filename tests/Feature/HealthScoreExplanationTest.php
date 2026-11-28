<?php

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
use Inertia\Testing\AssertableInertia as Assert;

function healthExplanationWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Health Explanation Workspace',
        'slug' => 'health-explain-'.uniqid(),
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
        'name' => 'Explain WHMCS',
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
            'whmcs' => ['billing' => ['overdue_unpaid_count' => 0]],
            'host' => ['disk_free_percent' => 5],
        ],
        'payload_sha256' => hash('sha256', 'health-explain-payload'),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

it('exposes waiting-for-first-check props when there is no health data', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Empty Health Workspace',
        'slug' => 'empty-health-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('health.score', null)
            ->where('health.low_score_message', null)
            ->where('health.top_open_finding', null)
            ->where('health.open_total_count', 0));
});

it('explains a zero health score with open critical counts and the top finding', function () {
    Carbon::setTestNow('2026-11-22 10:00:00');
    $workspace = healthExplanationWorkspace();

    foreach (range(1, 4) as $index) {
        DiagnosticFinding::create([
            'diagnostic_run_id' => $workspace['run']->id,
            'tenant_id' => $workspace['tenant']->id,
            'fingerprint' => hash('sha256', 'critical-'.$index),
            'issue_key' => 'storage.disk_low',
            'source' => 'rule',
            'affected_count' => 1,
            'fix_mode' => 'guided',
            'category' => 'host',
            'severity' => 'critical',
            'title' => 'Server disk free space is low',
            'summary' => 'test',
            'evidence' => [],
            'status' => DiagnosticFinding::STATUS_OPEN,
        ]);
    }

    app(HealthSnapshotRecorder::class)->recomputeForIntegration(
        $workspace['integration'],
        $workspace['run'],
    );

    $snapshot = WhmcsHealthSnapshot::query()
        ->where('integration_id', $workspace['integration']->id)
        ->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->score)->toBeLessThan(20);

    $this->actingAs($workspace['owner'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('health.score', $snapshot->score)
            ->where('health.open_critical_count', 4)
            ->where('health.open_total_count', 4)
            ->where('health.low_score_message', fn ($message) => is_string($message)
                && str_contains($message, 'Your score is')
                && str_contains($message, '4 critical'))
            ->where('health.top_open_finding.title', fn ($title) => is_string($title) && $title !== '')
            ->has('health.top_open_finding.id'));

    Carbon::setTestNow();
});

it('isolates low-score explanation findings by tenant', function () {
    Carbon::setTestNow('2026-11-22 12:00:00');
    $a = healthExplanationWorkspace();
    $b = healthExplanationWorkspace();

    DiagnosticFinding::create([
        'diagnostic_run_id' => $a['run']->id,
        'tenant_id' => $a['tenant']->id,
        'fingerprint' => hash('sha256', 'tenant-a-critical'),
        'issue_key' => 'storage.disk_low',
        'source' => 'rule',
        'affected_count' => 8,
        'fix_mode' => 'guided',
        'category' => 'host',
        'severity' => 'critical',
        'title' => 'Server disk free space is low',
        'summary' => 'tenant a',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    app(HealthSnapshotRecorder::class)->recomputeForIntegration($a['integration'], $a['run']);
    app(HealthSnapshotRecorder::class)->recomputeForIntegration($b['integration'], $b['run']);

    $this->actingAs($a['owner'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.open_critical_count', 1)
            ->has('health.top_open_finding.id')
            ->where('health.top_open_finding.title', 'Server disk free space is low'));

    $this->actingAs($b['owner'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.open_critical_count', 0)
            ->where('health.top_open_finding', null));

    Carbon::setTestNow();
});

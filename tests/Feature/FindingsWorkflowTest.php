<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRecommendation;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\FindingPlaybookBuilder;
use App\Services\Diagnostics\FindingRecheckComparer;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createFindingWorkspace(?User $owner = null, array $findingOverrides = [], array $runOverrides = []): array
{
    $owner ??= User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Findings Workspace',
        'slug' => 'findings-'.uniqid(),
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
        'name' => 'Primary WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create(array_merge([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7'], 'php' => ['version' => '8.2.0']],
        'payload_sha256' => str_repeat('c', 64),
        'analysis_summary' => 'Completed',
    ], $runOverrides));

    $finding = DiagnosticFinding::create(array_merge([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'cron-missing'),
        'category' => 'cron',
        'severity' => 'warning',
        'title' => 'System cron appears stalled',
        'summary' => 'The WHMCS system cron has not completed recently.',
        'evidence' => ['system.cron.last_run' => '2026-01-01T00:00:00Z'],
        'confidence' => 0.91,
        'status' => DiagnosticFinding::STATUS_OPEN,
    ], $findingOverrides));

    DiagnosticRecommendation::create([
        'diagnostic_finding_id' => $finding->id,
        'tenant_id' => $tenant->id,
        'recommendation' => 'Confirm the system cron job is scheduled every five minutes.',
        'rationale' => 'Stalled cron delays automation and invoice generation.',
        'risk_level' => 'medium',
        'requires_manual_action' => true,
    ]);

    return compact('owner', 'tenant', 'integration', 'run', 'finding');
}

it('renders the guided fix center playbook on finding detail', function () {
    ['owner' => $owner, 'finding' => $finding] = createFindingWorkspace();

    $this->actingAs($owner)
        ->get('/findings/'.$finding->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/show')
            ->where('finding.id', $finding->id)
            ->where('finding.status', 'open')
            ->where('can_manage', true)
            ->has('playbook.detected.evidence', 1)
            ->where('playbook.safe_steps.0.action', 'Confirm the system cron job is scheduled every five minutes.')
            ->where('playbook.change_risk', 'medium')
            ->has('playbook.checklist_text')
            ->has('recheck.status')
            ->has('assignees', 1)
            ->has('statuses', 4));
});

it('updates finding status assignee and note for operators', function () {
    ['owner' => $owner, 'tenant' => $tenant, 'finding' => $finding] = createFindingWorkspace();
    $operator = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $operator->id,
        'role' => TenantMembership::ROLE_OPERATOR,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($owner)
        ->patch('/findings/'.$finding->id, [
            'status' => DiagnosticFinding::STATUS_IN_PROGRESS,
            'assignee_user_id' => $operator->id,
            'status_note' => 'Working the cron fix in WHMCS',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Finding workflow updated.');

    $finding->refresh();

    expect($finding->status)->toBe(DiagnosticFinding::STATUS_IN_PROGRESS)
        ->and($finding->assignee_user_id)->toBe($operator->id)
        ->and($finding->status_note)->toBe('Working the cron fix in WHMCS')
        ->and($finding->status_updated_at)->not->toBeNull()
        ->and($finding->resolved_at)->toBeNull();

    $this->actingAs($owner)
        ->patch('/findings/'.$finding->id, [
            'status' => DiagnosticFinding::STATUS_RESOLVED,
            'assignee_user_id' => $operator->id,
            'status_note' => 'Verified after re-check',
        ])
        ->assertRedirect();

    $finding->refresh();

    expect($finding->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($finding->resolved_at)->not->toBeNull();
});

it('forbids viewers from updating finding workflow', function () {
    ['owner' => $owner, 'tenant' => $tenant, 'finding' => $finding] = createFindingWorkspace();
    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $viewer->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->patch('/findings/'.$finding->id, [
            'status' => DiagnosticFinding::STATUS_RESOLVED,
        ])
        ->assertForbidden();

    expect($finding->fresh()->status)->toBe(DiagnosticFinding::STATUS_OPEN);
});

it('returns 404 when updating another tenant finding', function () {
    ['finding' => $finding] = createFindingWorkspace();
    $outsider = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);

    $this->actingAs($outsider)
        ->patch('/findings/'.$finding->id, [
            'status' => DiagnosticFinding::STATUS_RESOLVED,
        ])
        ->assertNotFound();
});

it('rejects invalid finding status values', function () {
    ['owner' => $owner, 'finding' => $finding] = createFindingWorkspace();

    $this->actingAs($owner)
        ->from('/findings/'.$finding->id)
        ->patch('/findings/'.$finding->id, [
            'status' => 'autopilot_fixed',
        ])
        ->assertRedirect('/findings/'.$finding->id)
        ->assertSessionHasErrors('status');
});

it('compares finding fingerprints against a newer analyzed run', function () {
    ['owner' => $owner, 'integration' => $integration, 'tenant' => $tenant, 'finding' => $finding] = createFindingWorkspace();

    $newer = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('d', 64),
        'analysis_summary' => 'Clean',
        'no_findings_reason' => 'No evidence-backed issues remained.',
    ]);

    $this->actingAs($owner)
        ->post('/findings/'.$finding->id.'/recheck')
        ->assertRedirect()
        ->assertSessionHas('status');

    $result = app(FindingRecheckComparer::class)->compare($finding->fresh());

    expect($result['status'])->toBe('absent')
        ->and($result['latest_run']['id'])->toBe($newer->id);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $newer->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => $finding->fingerprint,
        'category' => 'cron',
        'severity' => 'warning',
        'title' => 'System cron appears stalled',
        'summary' => 'Still stalled.',
        'evidence' => ['system.cron.last_run' => '2026-01-01T00:00:00Z'],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    expect(app(FindingRecheckComparer::class)->compare($finding->fresh())['status'])->toBe('still_present');
});

it('shows last scan clean on findings index when analyzed and no active findings', function () {
    ['owner' => $owner, 'finding' => $finding] = createFindingWorkspace(null, [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get('/findings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/index')
            ->where('health.last_scan_clean', true)
            ->where('health.active_count', 0)
            ->has('filters.status'));

    expect($finding->status)->toBe(DiagnosticFinding::STATUS_RESOLVED);
});

it('dedupes the findings index to one current issue per fingerprint', function () {
    ['owner' => $owner, 'integration' => $integration, 'tenant' => $tenant, 'finding' => $finding] = createFindingWorkspace();

    $olderRun = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now()->subDay(),
        'received_at' => now()->subDay(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('e', 64),
        'analysis_summary' => 'Older',
    ]);

    $olderFinding = DiagnosticFinding::create([
        'diagnostic_run_id' => $olderRun->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => $finding->fingerprint,
        'category' => $finding->category,
        'severity' => $finding->severity,
        'title' => $finding->title,
        'summary' => 'Older sighting of the same issue.',
        'evidence' => $finding->evidence,
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);
    $olderFinding->forceFill(['created_at' => now()->subDay()])->save();

    $this->actingAs($owner)
        ->get('/findings?status=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/index')
            ->where('health.active_count', 1)
            ->has('findings.data', 1)
            ->where('findings.data.0.id', $finding->id));

    $this->actingAs($owner)
        ->get('/findings?status=history')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/index')
            ->has('findings.data', 2));
});

it('stabilizes known finding titles for fingerprint continuity', function () {
    $continuity = app(FindingContinuityService::class);

    $stabilized = $continuity->stabilizeFindingData([
        'category' => 'security',
        'title' => 'PHP error display is enabled',
        'evidence_paths' => ['system.error_display_disabled'],
    ]);

    expect($stabilized['title'])->toBe('PHP error display is not disabled')
        ->and($continuity->fingerprintFor($stabilized))->toBe($continuity->fingerprintFor([
            'category' => 'security',
            'title' => 'PHP error display is not disabled',
            'evidence_paths' => ['system.error_display_disabled'],
        ]));
});

it('builds human-readable playbook checklist text', function () {
    ['finding' => $finding] = createFindingWorkspace();
    $playbook = app(FindingPlaybookBuilder::class)->build($finding->load('recommendations'));

    expect($playbook['detected']['evidence'][0]['label'])->toContain('System')
        ->and($playbook['checklist_text'])->toContain('Guided Fix Checklist')
        ->and($playbook['checklist_text'])->toContain('Confirm the system cron job')
        ->and($playbook['deep_links'][0]['path'])->toContain('Cron');
});

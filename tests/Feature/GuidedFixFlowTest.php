<?php

use App\Models\AuditEvent;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\GuidedFixStep;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionExecutor;
use Illuminate\Support\Str;

function createGuidedWorkspace(array $entitlements = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Guided Workspace',
        'slug' => 'guided-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'plan_code' => 'starter',
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'entitlements' => array_merge([
            'diagnostics.analysis' => true,
            'safe_actions.enabled' => false,
            'fixes.guided' => true,
            'fixes.one_click' => false,
            'fixes.bulk' => false,
            'limits' => ['integrations' => 1, 'members' => 3, 'diagnostics_per_period' => 30, 'retention_days' => 14],
        ], $entitlements),
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Guided WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
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
        'payload_sha256' => hash('sha256', 'guided-payload'),
        'analysis_summary' => 'ok',
    ]);

    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'cron.not_running|'.$run->id),
        'issue_key' => 'cron.not_running',
        'source' => 'rule',
        'affected_count' => 1,
        'fix_mode' => 'guided',
        'category' => 'cron',
        'severity' => 'critical',
        'title' => 'WHMCS system cron is not running',
        'summary' => 'Cron stalled',
        'evidence' => ['system.cron.last_run' => null],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    return compact('owner', 'tenant', 'integration', 'run', 'finding');
}

it('marks guided steps done and audits completion', function () {
    $workspace = createGuidedWorkspace();

    $this->actingAs($workspace['owner'])
        ->post('/fix-center/'.$workspace['finding']->id.'/guided-steps', [
            'step_index' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Step marked done.');

    $step = GuidedFixStep::query()
        ->where('finding_id', $workspace['finding']->id)
        ->where('step_index', 1)
        ->first();

    expect($step)->not->toBeNull()
        ->and($step->completed_at)->not->toBeNull()
        ->and($step->completed_by_user_id)->toBe($workspace['owner']->id)
        ->and($step->tenant_id)->toBe($workspace['tenant']->id);

    expect(AuditEvent::query()->where('event_type', 'guided_fix.step_completed')->exists())->toBeTrue();
});

it('requests re-collect on guided complete and moves finding to fixing', function () {
    $workspace = createGuidedWorkspace();

    $this->actingAs($workspace['owner'])
        ->post('/fix-center/'.$workspace['finding']->id.'/complete-guide')
        ->assertRedirect()
        ->assertSessionHas('status');

    $workspace['finding']->refresh();
    $execution = SafeActionExecution::query()
        ->where('finding_id', $workspace['finding']->id)
        ->where('mode', SafeActionExecution::MODE_GUIDED)
        ->first();

    expect($workspace['finding']->status)->toBe(DiagnosticFinding::STATUS_IN_PROGRESS)
        ->and($execution)->not->toBeNull()
        ->and($execution->action_key)->toBe('pull_and_run_diagnostic_collect')
        ->and($execution->issue_key)->toBe('cron.not_running')
        ->and($execution->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT)
        ->and($workspace['integration']->fresh()->pending_diagnostic_collect_execution_id)->toBe($execution->id);
});

it('verifies guided completion only when the next scan no longer detects the issue', function () {
    $workspace = createGuidedWorkspace();

    $this->actingAs($workspace['owner'])
        ->post('/fix-center/'.$workspace['finding']->id.'/complete-guide');

    $execution = SafeActionExecution::query()->firstOrFail();

    app(SafeActionExecutor::class)->recordAddonResult(
        $execution,
        $workspace['integration']->fresh(),
        'success',
        'Diagnostic snapshot submitted by addon.',
        ['remediation_applied' => true, 'duplicate' => false],
    );

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_COMPLETED)
        ->and($workspace['finding']->fresh()->status)->toBe(DiagnosticFinding::STATUS_IN_PROGRESS);

    $stillPresentRun = DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('b', 64),
        'analysis_summary' => 'still present',
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $stillPresentRun->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $workspace['finding']->fingerprint,
        'issue_key' => 'cron.not_running',
        'source' => 'rule',
        'affected_count' => 1,
        'fix_mode' => 'guided',
        'category' => 'cron',
        'severity' => 'critical',
        'title' => 'WHMCS system cron is not running',
        'summary' => 'Cron stalled',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_IN_PROGRESS,
    ]);

    app(SafeActionExecutor::class)->verifyCollectFromSnapshot(
        $workspace['integration']->fresh(),
        $stillPresentRun,
    );

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_COMPLETED);

    $clearedRun = DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now()->addMinute(),
        'received_at' => now()->addMinute(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('c', 64),
        'analysis_summary' => 'clean',
    ]);

    app(SafeActionExecutor::class)->verifyCollectFromSnapshot(
        $workspace['integration']->fresh(),
        $clearedRun,
    );

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_VERIFIED)
        ->and($execution->minutes_saved)->toBe(45)
        ->and($execution->mode)->toBe(SafeActionExecution::MODE_GUIDED)
        ->and($workspace['finding']->fresh()->status)->toBe(DiagnosticFinding::STATUS_RESOLVED);
});

it('keeps guided-only safety classes on guided primary action even with one-click entitlement', function () {
    $workspace = createGuidedWorkspace([
        'fixes.one_click' => true,
        'fixes.bulk' => true,
        'safe_actions.enabled' => true,
    ]);

    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'service.orphan'),
        'issue_key' => 'service.orphan',
        'source' => 'rule',
        'affected_count' => 2,
        'fix_mode' => 'guided',
        'category' => 'other',
        'severity' => 'warning',
        'title' => 'Orphan services need manual cleanup',
        'summary' => 'Orphans',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fix-center/index')
            ->has('issues.data')
            ->where('issues.data', function ($rows) use ($finding): bool {
                $row = collect($rows)->firstWhere('id', $finding->id);

                return is_array($row)
                    && $row['primary_action'] === 'start_guide'
                    && $row['can_one_click'] === false
                    && $row['fix_mode'] === 'guided';
            }));
});

it('blocks guided actions without fixes.guided entitlement', function () {
    $workspace = createGuidedWorkspace(['fixes.guided' => false]);

    $this->actingAs($workspace['owner'])
        ->post('/fix-center/'.$workspace['finding']->id.'/guided-steps', [
            'step_index' => 1,
        ])
        ->assertSessionHasErrors('step_index');
});

it('isolates guided actions across tenants', function () {
    $workspace = createGuidedWorkspace();
    $outsider = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);

    $this->actingAs($outsider)
        ->post('/fix-center/'.$workspace['finding']->id.'/complete-guide')
        ->assertNotFound();
});

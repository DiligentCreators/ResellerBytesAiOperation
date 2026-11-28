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
use App\Services\Diagnostics\DeterministicFindingDetector;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\FindingWorkflowService;
use App\Services\Diagnostics\GuidedFixService;
use App\Services\Diagnostics\MergeDuplicateFindingsService;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\Reports\ReportMetricsService;
use App\Services\SafeActions\SafeActionExecutor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function loadSupersedeSnapshotFixture(string $name): array
{
    $decoded = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/snapshots/'.$name)),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * @return array{
 *     owner: User,
 *     tenant: Tenant,
 *     integration: WhmcsIntegration,
 *     positivePayload: array<string, mixed>,
 *     cleanPayload: array<string, mixed>
 * }
 */
function makeSupersedeWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Supersede Workspace',
        'slug' => 'supersede-'.uniqid(),
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
        'plan_code' => 'growth',
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 14900,
        'entitlements' => [
            'diagnostics.analysis' => true,
            'safe_actions.enabled' => true,
            'fixes.guided' => true,
            'fixes.one_click' => true,
            'fixes.bulk' => true,
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Supersede WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
    ]);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'integration' => $integration,
        'positivePayload' => loadSupersedeSnapshotFixture('schema_1_0_positive.json'),
        'cleanPayload' => loadSupersedeSnapshotFixture('schema_1_0_clean.json'),
    ];
}

function makeSupersedeRun(WhmcsIntegration $integration, array $payload): DiagnosticRun
{
    return DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $integration->tenant_id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => $integration->addon_version ?? '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => $payload,
        'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR).'|'.uniqid()),
        'analysis_summary' => 'ok',
    ]);
}

it('keeps exactly one open copy per fingerprint after consecutive snapshots', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $workspace = makeSupersedeWorkspace();
    $detector = app(DeterministicFindingDetector::class);
    $findingCountBefore = DiagnosticFinding::query()->count();

    $firstRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($firstRun, $workspace['owner']);

    $firstFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $firstRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    Carbon::setTestNow('2026-11-22T12:05:00Z');
    $secondRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($secondRun, $workspace['owner']);

    $secondFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $secondRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    $firstFinding->refresh();

    $openCopies = DiagnosticFinding::query()
        ->where('fingerprint', $secondFinding->fingerprint)
        ->whereHas('diagnosticRun', fn ($query) => $query->where('integration_id', $workspace['integration']->id))
        ->active()
        ->get();

    expect($openCopies)->toHaveCount(1)
        ->and($openCopies->first()?->id)->toBe($secondFinding->id)
        ->and($firstFinding->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($firstFinding->merged_into_finding_id)->toBe($secondFinding->id)
        ->and($firstFinding->status_note)->toContain('Superseded by finding '.$secondFinding->id)
        ->and($firstFinding->status_note)->toContain('from run '.$secondRun->id)
        ->and(DiagnosticFinding::query()->count())->toBeGreaterThan($findingCountBefore);

    $event = AuditEvent::query()
        ->where('event_type', 'diagnostic_finding.workflow_updated')
        ->where('subject_id', $firstFinding->id)
        ->latest('chain_sequence')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->actor_type)->toBe('system')
        ->and($event?->actor_id)->toBeNull()
        ->and($event?->metadata['after']['status_note'] ?? null)->toContain('Superseded by finding '.$secondFinding->id);

    $groups = app(MergeDuplicateFindingsService::class)
        ->duplicateGroups($workspace['tenant']->id, $workspace['integration']->id);

    expect($groups)->toBe([]);
});

it('does not inflate issues_fixed when superseding prior open copies', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $workspace = makeSupersedeWorkspace();
    $detector = app(DeterministicFindingDetector::class);
    $metrics = app(ReportMetricsService::class);

    $firstRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($firstRun, $workspace['owner']);

    $periodStart = now()->subDays(7);
    $periodEnd = now()->addHour();
    $before = $metrics->build($workspace['tenant'], $periodStart, $periodEnd);

    Carbon::setTestNow('2026-11-22T12:05:00Z');
    $secondRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($secondRun, $workspace['owner']);

    $after = $metrics->build($workspace['tenant'], $periodStart, $periodEnd);

    expect($after['issues_fixed'])->toBe($before['issues_fixed'])
        ->and($after['resolved_this_week'])->toBe($before['resolved_this_week']);
});

it('carries in_progress assignee onto the new copy before superseding the older one', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $workspace = makeSupersedeWorkspace();
    $detector = app(DeterministicFindingDetector::class);

    $firstRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($firstRun, $workspace['owner']);

    $firstFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $firstRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    app(FindingWorkflowService::class)->update($firstFinding, $workspace['owner'], [
        'status' => DiagnosticFinding::STATUS_IN_PROGRESS,
        'assignee_user_id' => $workspace['owner']->id,
        'status_note' => 'Working on display_errors.',
    ]);

    Carbon::setTestNow('2026-11-22T12:05:00Z');
    $secondRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($secondRun, $workspace['owner']);

    $secondFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $secondRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    expect($secondFinding->status)->toBe(DiagnosticFinding::STATUS_IN_PROGRESS)
        ->and($secondFinding->assignee_user_id)->toBe($workspace['owner']->id)
        ->and($secondFinding->status_note)->toBe('Working on display_errors.')
        ->and($firstFinding->fresh()->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($firstFinding->fresh()->merged_into_finding_id)->toBe($secondFinding->id);
});

it('verifies an awaiting_agent safe action from an older copy against the current finding', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $workspace = makeSupersedeWorkspace();
    $detector = app(DeterministicFindingDetector::class);

    $firstRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($firstRun, $workspace['owner']);

    $firstFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $firstRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    $execution = SafeActionExecution::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'finding_id' => $firstFinding->id,
        'issue_key' => 'php.display_errors_on',
        'action_key' => 'disable_php_display_errors',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_COMPLETED,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'approved_by_user_id' => $workspace['owner']->id,
        'requires_password_confirm' => false,
        'input' => [],
        'items_total' => 1,
        'items_succeeded' => 1,
        'requested_at' => now(),
        'approved_at' => now(),
        'executed_at' => now(),
        'after_evidence' => ['remediation_applied' => true],
    ]);

    $workspace['integration']->forceFill([
        'pending_diagnostic_collect_at' => now(),
        'pending_diagnostic_collect_execution_id' => $execution->id,
    ])->save();

    Carbon::setTestNow('2026-11-22T12:05:00Z');
    $secondRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($secondRun, $workspace['owner']);

    $secondFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $secondRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    expect($firstFinding->fresh()->merged_into_finding_id)->toBe($secondFinding->id)
        ->and($secondFinding->isActive())->toBeTrue();

    $clearedPayload = $workspace['cleanPayload'];
    Carbon::setTestNow('2026-11-22T12:10:00Z');
    $verifyRun = makeSupersedeRun($workspace['integration'], $clearedPayload);

    app(SafeActionExecutor::class)->verifyCollectFromSnapshot(
        $workspace['integration']->fresh(),
        $verifyRun,
    );

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_VERIFIED)
        ->and($secondFinding->fresh()->status)->toBe(DiagnosticFinding::STATUS_RESOLVED);
});

it('shows guided-fix steps completed on an older copy for the current Fix Center issue', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $workspace = makeSupersedeWorkspace();
    $detector = app(DeterministicFindingDetector::class);

    $firstRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($firstRun, $workspace['owner']);

    $firstFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $firstRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    GuidedFixStep::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'finding_id' => $firstFinding->id,
        'step_index' => 1,
        'completed_by_user_id' => $workspace['owner']->id,
        'completed_at' => now(),
    ]);

    Carbon::setTestNow('2026-11-22T12:05:00Z');
    $secondRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($secondRun, $workspace['owner']);

    $secondFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $secondRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    $steps = app(GuidedFixService::class)->stepsForFinding($secondFinding);
    $firstStep = collect($steps)->firstWhere('step_index', 1);

    expect($firstStep)->not->toBeNull()
        ->and($firstStep['completed'])->toBeTrue()
        ->and($firstStep['completed_by_user_id'])->toBe($workspace['owner']->id);

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->has('issues.data')
            ->where('issues.data', function ($issues) use ($secondFinding): bool {
                $match = collect($issues)->firstWhere('id', $secondFinding->id);
                if ($match === null) {
                    return false;
                }

                $step = collect($match['guided_steps'] ?? [])->firstWhere('step_index', 1);

                return ($step['completed'] ?? false) === true;
            }));
});

it('resolves an absent issue as a genuine fix without merging', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $workspace = makeSupersedeWorkspace();
    $detector = app(DeterministicFindingDetector::class);
    $metrics = app(ReportMetricsService::class);

    $firstRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($firstRun, $workspace['owner']);

    $firstFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $firstRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    $periodStart = now()->subDays(7);
    $periodEnd = now()->addHour();
    $before = $metrics->build($workspace['tenant'], $periodStart, $periodEnd);

    Carbon::setTestNow('2026-11-22T12:05:00Z');
    $cleanRun = makeSupersedeRun($workspace['integration'], $workspace['cleanPayload']);
    $detector->detect($cleanRun, $workspace['owner']);

    $firstFinding->refresh();
    $after = $metrics->build($workspace['tenant'], $periodStart, $periodEnd);

    expect($firstFinding->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($firstFinding->merged_into_finding_id)->toBeNull()
        ->and($firstFinding->status_note)->toContain('Cleared on latest analyzed scan '.$cleanRun->id)
        ->and($after['issues_fixed'])->toBeGreaterThan($before['issues_fixed']);
});

it('is idempotent when supersedePriorOpenCopies runs twice on the same finding', function () {
    Carbon::setTestNow('2026-11-22T12:00:00Z');
    $workspace = makeSupersedeWorkspace();
    $detector = app(DeterministicFindingDetector::class);
    $continuity = app(FindingContinuityService::class);

    $firstRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($firstRun, $workspace['owner']);

    Carbon::setTestNow('2026-11-22T12:05:00Z');
    $secondRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $detector->detect($secondRun, $workspace['owner']);

    $secondFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $secondRun->id)
        ->where('issue_key', 'php.display_errors_on')
        ->sole();

    $auditBefore = AuditEvent::query()->count();
    $findingCountBefore = DiagnosticFinding::query()->count();

    $continuity->supersedePriorOpenCopies($secondFinding);

    expect(AuditEvent::query()->count())->toBe($auditBefore)
        ->and(DiagnosticFinding::query()->count())->toBe($findingCountBefore)
        ->and(
            DiagnosticFinding::query()
                ->where('fingerprint', $secondFinding->fingerprint)
                ->whereHas('diagnosticRun', fn ($query) => $query->where('integration_id', $workspace['integration']->id))
                ->active()
                ->count(),
        )->toBe(1);
});

it('resolves to the current copy through the merged_into chain', function () {
    $workspace = makeSupersedeWorkspace();
    $catalog = WhmcsIssueCatalog::get('php.display_errors_on');
    expect($catalog)->not->toBeNull();

    $continuity = app(FindingContinuityService::class);
    $findingData = [
        'category' => (string) ($catalog['category'] ?? 'php'),
        'title' => (string) ($catalog['title'] ?? 'PHP error display is not disabled'),
        'evidence_paths' => ['system.error_display_disabled'],
    ];
    $fingerprint = $continuity->fingerprintForIssueKey('php.display_errors_on', $findingData);

    $olderRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);
    $newerRun = makeSupersedeRun($workspace['integration'], $workspace['positivePayload']);

    $older = DiagnosticFinding::create([
        'diagnostic_run_id' => $olderRun->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $fingerprint,
        'issue_key' => 'php.display_errors_on',
        'source' => DiagnosticFinding::SOURCE_RULE,
        'category' => $findingData['category'],
        'severity' => 'warning',
        'title' => $findingData['title'],
        'summary' => 'older',
        'evidence' => ['system.error_display_disabled' => false],
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now(),
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $newer = DiagnosticFinding::create([
        'diagnostic_run_id' => $newerRun->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $fingerprint,
        'issue_key' => 'php.display_errors_on',
        'source' => DiagnosticFinding::SOURCE_RULE,
        'category' => $findingData['category'],
        'severity' => 'warning',
        'title' => $findingData['title'],
        'summary' => 'newer',
        'evidence' => ['system.error_display_disabled' => false],
        'status' => DiagnosticFinding::STATUS_OPEN,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $older->forceFill(['merged_into_finding_id' => $newer->id])->save();

    expect($continuity->resolveToCurrentCopy($older)->id)->toBe($newer->id);
});

<?php

use App\Models\AuditEvent;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\MergeDuplicateFindingsService;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\Reports\ReportMetricsService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @return array{
 *     owner: User,
 *     tenant: Tenant,
 *     integration: WhmcsIntegration,
 *     run: DiagnosticRun,
 *     rule: DiagnosticFinding,
 *     ai: DiagnosticFinding,
 *     ruleFingerprint: string,
 *     legacyAiFingerprint: string
 * }
 */
function makeMergeDuplicatesWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Merge Dup Workspace',
        'slug' => 'merge-dup-'.uniqid(),
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
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Merge Dup WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => [
            'system' => [
                'error_display_disabled' => false,
                'web_php_version' => '8.3.0',
            ],
        ],
        'payload_sha256' => hash('sha256', 'merge-dup-payload-'.uniqid()),
        'analysis_summary' => 'ok',
    ]);

    $continuity = app(FindingContinuityService::class);
    $catalog = WhmcsIssueCatalog::get('php.display_errors_on');
    expect($catalog)->not->toBeNull();

    $ruleFindingData = [
        'category' => (string) ($catalog['category'] ?? 'php'),
        'title' => (string) ($catalog['title'] ?? 'PHP error display is not disabled'),
        'evidence_paths' => ['system.error_display_disabled'],
    ];
    $ruleFingerprint = $continuity->fingerprintForIssueKey('php.display_errors_on', $ruleFindingData);

    $legacyAiFingerprint = $continuity->fingerprintFor([
        'category' => 'security',
        'title' => 'PHP error display appears enabled',
        'evidence_paths' => ['system.error_display_disabled'],
    ]);

    $ai = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => $legacyAiFingerprint,
        'issue_key' => null,
        'source' => DiagnosticFinding::SOURCE_AI,
        'category' => 'security',
        'severity' => 'warning',
        'title' => 'PHP error display appears enabled',
        'summary' => 'AI duplicate of display errors.',
        'evidence' => ['system.error_display_disabled' => false],
        'confidence' => 0.9,
        'status' => DiagnosticFinding::STATUS_OPEN,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $rule = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => $ruleFingerprint,
        'issue_key' => 'php.display_errors_on',
        'source' => DiagnosticFinding::SOURCE_RULE,
        'category' => $ruleFindingData['category'],
        'severity' => 'warning',
        'title' => $ruleFindingData['title'],
        'summary' => 'Rule finding for display errors.',
        'evidence' => ['system.error_display_disabled' => false],
        'confidence' => 1,
        'status' => DiagnosticFinding::STATUS_OPEN,
        'fix_mode' => 'one_click',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact(
        'owner',
        'tenant',
        'integration',
        'run',
        'rule',
        'ai',
        'ruleFingerprint',
        'legacyAiFingerprint',
    );
}

it('dry-runs findings:merge-duplicates without changing statuses or appending audit events', function () {
    $workspace = makeMergeDuplicatesWorkspace();
    $auditBefore = AuditEvent::query()->count();
    $findingCountBefore = DiagnosticFinding::query()->count();

    $this->artisan('findings:merge-duplicates', [
        '--tenant' => $workspace['tenant']->id,
        '--integration' => $workspace['integration']->id,
    ])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Duplicate groups: 1')
        ->expectsOutputToContain('Findings to resolve: 1')
        ->expectsOutputToContain('Resolved this run: 0')
        ->assertSuccessful();

    $groups = app(MergeDuplicateFindingsService::class)
        ->duplicateGroups($workspace['tenant']->id, $workspace['integration']->id);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['canonical_key'])->toBe('php.display_errors_on')
        ->and($groups[0]['kept_finding_id'])->toBe($workspace['rule']->id)
        ->and($groups[0]['resolve_finding_ids'])->toBe([$workspace['ai']->id])
        ->and($workspace['rule']->fresh()->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and($workspace['ai']->fresh()->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and(AuditEvent::query()->count())->toBe($auditBefore)
        ->and(DiagnosticFinding::query()->count())->toBe($findingCountBefore);
});

it('applies findings:merge-duplicates by resolving older AI duplicates and keeping the rule row', function () {
    $workspace = makeMergeDuplicatesWorkspace();
    $auditBefore = AuditEvent::query()->count();
    $findingCountBefore = DiagnosticFinding::query()->count();

    $this->artisan('findings:merge-duplicates', ['--apply' => true])
        ->expectsOutputToContain('Resolved 1 duplicate finding')
        ->assertSuccessful();

    $ai = $workspace['ai']->fresh();
    $rule = $workspace['rule']->fresh();

    expect($rule->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and($ai->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($ai->merged_into_finding_id)->toBe($rule->id)
        ->and($rule->merged_into_finding_id)->toBeNull()
        ->and($ai->status_note)->toContain('Merged into duplicate finding '.$rule->id)
        ->and($ai->status_note)->toContain('findings:merge-duplicates')
        ->and(DiagnosticFinding::query()->count())->toBe($findingCountBefore)
        ->and(AuditEvent::query()->count())->toBeGreaterThan($auditBefore);

    $event = AuditEvent::query()
        ->where('event_type', 'diagnostic_finding.workflow_updated')
        ->where('subject_id', $ai->id)
        ->latest('chain_sequence')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->actor_type)->toBe('system')
        ->and($event?->actor_id)->toBeNull();
});

it('is idempotent when findings:merge-duplicates --apply runs twice', function () {
    $workspace = makeMergeDuplicatesWorkspace();

    $this->artisan('findings:merge-duplicates', ['--apply' => true])->assertSuccessful();

    $auditAfterFirst = AuditEvent::query()->count();
    $aiStatusNote = $workspace['ai']->fresh()->status_note;

    $this->artisan('findings:merge-duplicates', ['--apply' => true])
        ->expectsOutputToContain('No active duplicate finding groups found')
        ->expectsOutputToContain('Resolved this run: 0')
        ->assertSuccessful();

    expect($workspace['ai']->fresh()->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($workspace['ai']->fresh()->merged_into_finding_id)->toBe($workspace['rule']->id)
        ->and($workspace['ai']->fresh()->status_note)->toBe($aiStatusNote)
        ->and($workspace['rule']->fresh()->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and(AuditEvent::query()->count())->toBe($auditAfterFirst);
});

it('does not inflate ReportMetricsService issues_fixed when merging duplicates', function () {
    $workspace = makeMergeDuplicatesWorkspace();

    DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'genuine-fix|'.uniqid()),
        'category' => 'security',
        'severity' => 'warning',
        'title' => 'Genuinely fixed issue',
        'summary' => 'A real fix, not a merge.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subDay(),
    ]);

    $periodStart = now()->subDays(7);
    $periodEnd = now();
    $metrics = app(ReportMetricsService::class);

    $before = $metrics->build($workspace['tenant'], $periodStart, $periodEnd);

    $this->artisan('findings:merge-duplicates', [
        '--tenant' => $workspace['tenant']->id,
        '--apply' => true,
    ])->assertSuccessful();

    $after = $metrics->build($workspace['tenant'], $periodStart, $periodEnd);
    $ai = $workspace['ai']->fresh();

    expect($before['issues_fixed'])->toBe(1)
        ->and($after['issues_fixed'])->toBe($before['issues_fixed'])
        ->and($after['resolved_this_week'])->toBe($before['resolved_this_week'])
        ->and($ai->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($ai->merged_into_finding_id)->toBe($workspace['rule']->id)
        ->and($ai->resolved_at)->not->toBeNull();
});

it('still counts genuinely resolved findings in ReportMetricsService issues_fixed', function () {
    $workspace = makeMergeDuplicatesWorkspace();

    $this->artisan('findings:merge-duplicates', [
        '--tenant' => $workspace['tenant']->id,
        '--apply' => true,
    ])->assertSuccessful();

    expect($workspace['ai']->fresh()->merged_into_finding_id)->toBe($workspace['rule']->id);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'genuine-after-merge|'.uniqid()),
        'category' => 'security',
        'severity' => 'warning',
        'title' => 'Fixed after merge',
        'summary' => 'Real resolution.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now(),
        'merged_into_finding_id' => null,
    ]);

    $metrics = app(ReportMetricsService::class)->build(
        $workspace['tenant'],
        now()->subDays(7),
        now(),
    );

    expect($metrics['issues_fixed'])->toBe(1)
        ->and($metrics['resolved_this_week'])->toBe(1);
});

it('adds merged_into_finding_id on diagnostic_findings via migration on sqlite', function () {
    expect(Schema::hasColumn('diagnostic_findings', 'merged_into_finding_id'))->toBeTrue();
});

it('scopes findings:merge-duplicates --tenant without touching another tenant', function () {
    $first = makeMergeDuplicatesWorkspace();
    $second = makeMergeDuplicatesWorkspace();
    $auditBefore = AuditEvent::query()->count();

    $this->artisan('findings:merge-duplicates', [
        '--tenant' => $first['tenant']->id,
        '--apply' => true,
    ])->assertSuccessful();

    expect($first['ai']->fresh()->status)->toBe(DiagnosticFinding::STATUS_RESOLVED)
        ->and($first['rule']->fresh()->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and($second['ai']->fresh()->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and($second['rule']->fresh()->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and(AuditEvent::query()->count())->toBeGreaterThan($auditBefore);
});

it('never deletes finding rows when merging duplicates', function () {
    $workspace = makeMergeDuplicatesWorkspace();
    $ids = [$workspace['rule']->id, $workspace['ai']->id];
    $countBefore = DiagnosticFinding::query()->whereIn('id', $ids)->count();

    $this->artisan('findings:merge-duplicates', ['--apply' => true])->assertSuccessful();

    expect(DiagnosticFinding::query()->whereIn('id', $ids)->count())->toBe($countBefore)
        ->and(DiagnosticFinding::query()->whereKey($workspace['rule']->id)->exists())->toBeTrue()
        ->and(DiagnosticFinding::query()->whereKey($workspace['ai']->id)->exists())->toBeTrue();
});

it('fails findings:merge-duplicates when --tenant does not match', function () {
    $this->artisan('findings:merge-duplicates', [
        '--tenant' => (string) Str::uuid(),
    ])
        ->expectsOutputToContain('No tenant matched')
        ->assertFailed();
});

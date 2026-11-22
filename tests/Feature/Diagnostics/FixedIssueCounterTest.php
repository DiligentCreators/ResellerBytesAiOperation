<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FixedIssueCounter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, tenant: Tenant, integration: WhmcsIntegration, run: DiagnosticRun}
 */
function createFixedIssueCounterWorkspace(string $slugPrefix = 'fixed-counter'): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Fixed Counter Workspace',
        'slug' => $slugPrefix.'-'.uniqid(),
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
        'name' => 'Fixed Counter WHMCS',
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
        'collected_at' => now()->subHours(2),
        'received_at' => now()->subHours(2),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => hash('sha256', 'fixed-counter-run-1'),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

function createFixedIssueCounterRun(array $workspace, string $payloadSeed): DiagnosticRun
{
    return DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => hash('sha256', $payloadSeed),
        'analysis_summary' => 'ok',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createFixedIssueCounterFinding(
    array $workspace,
    DiagnosticRun $run,
    string $fingerprint,
    array $overrides = [],
): DiagnosticFinding {
    $createdAt = $overrides['created_at'] ?? null;
    $updatedAt = $overrides['updated_at'] ?? null;
    unset($overrides['created_at'], $overrides['updated_at']);

    $finding = DiagnosticFinding::create(array_merge([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => $fingerprint,
        'issue_key' => 'service.suspended_but_paid',
        'source' => 'rule',
        'affected_count' => 1,
        'fix_mode' => 'one_click',
        'category' => 'services',
        'severity' => 'warning',
        'title' => $fingerprint,
        'summary' => $fingerprint,
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ], $overrides));

    if ($createdAt !== null || $updatedAt !== null) {
        $finding->forceFill([
            'created_at' => $createdAt ?? $finding->created_at,
            'updated_at' => $updatedAt ?? $finding->updated_at,
        ])->save();
    }

    return $finding->fresh() ?? $finding;
}

it('aligns fix center fixed count and billing issues_fixed on the shared per-issue definition', function () {
    $this->withoutVite();
    $workspace = createFixedIssueCounterWorkspace();
    $olderRun = $workspace['run'];
    $currentRun = createFixedIssueCounterRun($workspace, 'fixed-counter-run-2');

    $sharedFingerprint = hash('sha256', 'shared-resolved-issue');
    $otherFingerprint = hash('sha256', 'other-resolved-issue');
    $acceptedFingerprint = hash('sha256', 'accepted-risk-issue');
    $mergedFingerprint = hash('sha256', 'merged-copy-issue');
    $openFingerprint = hash('sha256', 'open-issue');

    // Same issue resolved in two runs — only the current copy counts once.
    createFixedIssueCounterFinding($workspace, $olderRun, $sharedFingerprint, [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subDays(2),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    $currentShared = createFixedIssueCounterFinding($workspace, $currentRun, $sharedFingerprint, [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subHour(),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $otherResolved = createFixedIssueCounterFinding($workspace, $currentRun, $otherFingerprint, [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subMinutes(30),
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);

    createFixedIssueCounterFinding($workspace, $currentRun, $acceptedFingerprint, [
        'status' => DiagnosticFinding::STATUS_ACCEPTED_RISK,
        'resolved_at' => now()->subMinutes(20),
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    createFixedIssueCounterFinding($workspace, $currentRun, $mergedFingerprint, [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subMinutes(15),
        'merged_into_finding_id' => $otherResolved->id,
        'created_at' => now()->subMinutes(15),
        'updated_at' => now()->subMinutes(15),
    ]);

    createFixedIssueCounterFinding($workspace, $currentRun, $openFingerprint, [
        'status' => DiagnosticFinding::STATUS_OPEN,
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $otherWorkspace = createFixedIssueCounterWorkspace('fixed-counter-other');
    createFixedIssueCounterFinding($otherWorkspace, $otherWorkspace['run'], hash('sha256', 'other-tenant-fixed'), [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subMinutes(10),
    ]);

    $counter = app(FixedIssueCounter::class);

    expect($counter->countForTenant($workspace['tenant']))->toBe(2)
        ->and($counter->countForUser($workspace['owner']))->toBe(2)
        ->and($counter->countForTenant($otherWorkspace['tenant']))->toBe(1);

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=fixed')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->where('filters.tab', 'fixed')
            ->where('counts.fixed', 2)
            ->where('counts.ignored', 1)
            ->has('issues.data', 2)
            ->where('issues.data', function ($issues) use ($currentShared, $otherResolved): bool {
                $ids = collect($issues)->pluck('id')->sort()->values()->all();

                return $ids === collect([$currentShared->id, $otherResolved->id])->sort()->values()->all();
            }));

    $this->actingAs($workspace['owner'])
        ->get(route('billing.settings.show', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/settings')
            ->where('cancelValueReminder.issues_fixed', 2)
            ->where('cancelValueReminder.time_saved_period_label', 'this billing period'));
});

it('does not count accepted_risk as fixed and keeps it on the ignored tab', function () {
    $this->withoutVite();
    $workspace = createFixedIssueCounterWorkspace('fixed-accepted');
    $fingerprint = hash('sha256', 'accepted-only');

    createFixedIssueCounterFinding($workspace, $workspace['run'], $fingerprint, [
        'status' => DiagnosticFinding::STATUS_ACCEPTED_RISK,
        'resolved_at' => now()->subHour(),
    ]);

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=ignored')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->where('filters.tab', 'ignored')
            ->where('counts.fixed', 0)
            ->where('counts.ignored', 1)
            ->has('issues.data', 1));
});

it('applies an optional resolved_at period without double-counting run copies', function () {
    $workspace = createFixedIssueCounterWorkspace('fixed-period');
    $olderRun = $workspace['run'];
    $currentRun = createFixedIssueCounterRun($workspace, 'fixed-period-run-2');
    $fingerprint = hash('sha256', 'period-shared');

    createFixedIssueCounterFinding($workspace, $olderRun, $fingerprint, [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subDays(10),
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);
    createFixedIssueCounterFinding($workspace, $currentRun, $fingerprint, [
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subDay(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $counter = app(FixedIssueCounter::class);

    expect($counter->countForTenant($workspace['tenant'], now()->subDays(3), now()))->toBe(1)
        ->and($counter->countForTenant($workspace['tenant'], now()->subDays(20), now()->subDays(5)))->toBe(0)
        ->and($counter->countForTenant($workspace['tenant']))->toBe(1);
});

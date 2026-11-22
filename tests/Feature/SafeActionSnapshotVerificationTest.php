<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionExecutor;
use Illuminate\Support\Str;

function createSnapshotVerificationWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Snapshot Verify Workspace',
        'slug' => 'snap-verify-'.uniqid(),
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
        'unit_amount_minor' => fake()->numberBetween(10_000, 20_000),
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
        'name' => 'Snapshot Verify WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.2.0',
        'last_seen_at' => now()->subHour(),
    ]);

    $payload = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/snapshots/schema_1_1_positive.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => $payload,
        'payload_sha256' => hash('sha256', json_encode($payload)),
        'analysis_summary' => 'ok',
    ]);

    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'tickets.sla_breach|'.$run->id),
        'issue_key' => 'tickets.sla_breach',
        'source' => 'rule',
        'affected_count' => 2,
        'fix_mode' => 'one_click',
        'category' => 'tickets',
        'severity' => 'warning',
        'title' => 'tickets.sla_breach',
        'summary' => 'SLA tickets overdue',
        'evidence' => [
            'whmcs.tickets.sla_breach.ticket_ids' => [701, 702],
            'whmcs.tickets.sla_breach.count' => 2,
        ],
        'status' => 'open',
    ]);

    return compact('owner', 'tenant', 'integration', 'run', 'finding');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{body: string, headers: array<string, string>}
 */
function makeSignedVerifySnapshot(WhmcsIntegration $integration, string $fixture, array $overrides = []): array
{
    $basePayload = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/snapshots/'.$fixture)),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $payload = [
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.0',
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'payload' => array_replace_recursive($basePayload, $overrides['payload'] ?? []),
    ];
    unset($overrides['payload']);
    $payload = array_replace_recursive($payload, $overrides);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $nonce = Str::random(64);
    $path = '/api/v1/whmcs/diagnostic-snapshots';
    $canonical = implode(chr(10), [
        'POST',
        $path,
        (string) $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);

    return [
        'body' => $body,
        'headers' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => hash_hmac('sha256', $canonical, $integration->secret),
        ],
    ];
}

/**
 * @param  array<string, mixed>|null  $payload
 * @return array{body: string, headers: array<string, string>}
 */
function makeSignedVerifyAgentRequest(
    WhmcsIntegration $integration,
    string $method,
    string $path,
    ?array $payload = null,
): array {
    $body = $payload === null
        ? ($method === 'GET' ? '' : '{}')
        : json_encode($payload, JSON_THROW_ON_ERROR);

    $timestamp = now()->timestamp;
    $nonce = Str::random(64);
    $canonical = implode(chr(10), [
        strtoupper($method),
        $path,
        (string) $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);

    return [
        'body' => $body,
        'headers' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => hash_hmac('sha256', $canonical, $integration->secret),
        ],
    ];
}

function approveTicketEscalate(array $workspace, int $ticketId = 701): SafeActionExecution
{
    test()->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'ticket_escalate_sla',
            'finding_id' => $workspace['finding']->id,
            'input' => ['target_ids' => [$ticketId]],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();

    test()->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Escalate SLA ticket '.$ticketId,
        ])
        ->assertRedirect();

    return $execution->fresh();
}

it('keeps awaiting_agent executions unverified and in the pending queue after a positive snapshot', function () {
    $workspace = createSnapshotVerificationWorkspace();
    $execution = approveTicketEscalate($workspace);

    expect($execution->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT);

    $signed = makeSignedVerifySnapshot($workspace['integration'], 'schema_1_1_positive.json');
    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT)
        ->and($execution->verified_at)->toBeNull();

    $newRun = DiagnosticRun::query()
        ->where('integration_id', $workspace['integration']->id)
        ->latest('received_at')
        ->firstOrFail();

    expect(DiagnosticFinding::query()
        ->where('diagnostic_run_id', $newRun->id)
        ->where('issue_key', 'tickets.sla_breach')
        ->where('source', 'rule')
        ->value('affected_count'))->toBe(2);

    $pending = makeSignedVerifyAgentRequest($workspace['integration'], 'GET', '/api/v1/whmcs/safe-actions/pending');
    $this->call('GET', '/api/v1/whmcs/safe-actions/pending', [], [], [], $pending['headers'], $pending['body'])
        ->assertOk()
        ->assertJsonPath('data.actions.0.id', $execution->id);
});

it('verifies an addon-reported applied execution when the next snapshot clears the issue', function () {
    $workspace = createSnapshotVerificationWorkspace();
    $execution = approveTicketEscalate($workspace);

    app(SafeActionExecutor::class)->recordAddonResult(
        $execution,
        $workspace['integration']->fresh(),
        'success',
        'ticket_escalate_sla applied',
        [
            'remediation_applied' => true,
            'item_results' => [['id' => 701, 'status' => 'success']],
        ],
    );

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_COMPLETED)
        ->and($execution->executed_at)->not->toBeNull();

    $signed = makeSignedVerifySnapshot($workspace['integration'], 'schema_1_1_clean.json');
    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_VERIFIED)
        ->and($execution->verified_at)->not->toBeNull()
        ->and($execution->items_succeeded)->toBeGreaterThanOrEqual(1);
});

it('does not verify an applied execution when the snapshot still shows the issue unchanged', function () {
    $workspace = createSnapshotVerificationWorkspace();
    $execution = approveTicketEscalate($workspace);

    app(SafeActionExecutor::class)->recordAddonResult(
        $execution,
        $workspace['integration']->fresh(),
        'success',
        'ticket_escalate_sla applied',
        [
            'remediation_applied' => true,
            'item_results' => [['id' => 701, 'status' => 'success']],
        ],
    );

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_COMPLETED);

    $signed = makeSignedVerifySnapshot($workspace['integration'], 'schema_1_1_positive.json');
    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_COMPLETED)
        ->and($execution->verified_at)->toBeNull();

    $newRun = DiagnosticRun::query()
        ->where('integration_id', $workspace['integration']->id)
        ->latest('received_at')
        ->first();

    expect(DiagnosticFinding::query()
        ->where('diagnostic_run_id', $newRun->id)
        ->where('issue_key', 'tickets.sla_breach')
        ->where('source', 'rule')
        ->exists())->toBeTrue();
});

it('runs verification after findings for the new run exist so positive snapshots cannot false-clear', function () {
    $workspace = createSnapshotVerificationWorkspace();
    $execution = approveTicketEscalate($workspace);

    app(SafeActionExecutor::class)->recordAddonResult(
        $execution,
        $workspace['integration']->fresh(),
        'success',
        'ticket_escalate_sla applied',
        [
            'remediation_applied' => true,
            'item_results' => [['id' => 701, 'status' => 'success']],
        ],
    );

    // Positive snapshot still reports the same SLA breach. If verification ran before
    // DeterministicFindingDetector, findingClearedOrReduced would see no finding row
    // for the new run and incorrectly mark this verified.
    $signed = makeSignedVerifySnapshot($workspace['integration'], 'schema_1_1_positive.json');
    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $newRun = DiagnosticRun::query()
        ->where('integration_id', $workspace['integration']->id)
        ->latest('received_at')
        ->firstOrFail();

    $newFinding = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $newRun->id)
        ->where('issue_key', 'tickets.sla_breach')
        ->where('source', 'rule')
        ->first();

    expect($newFinding)->not->toBeNull()
        ->and((int) $newFinding->affected_count)->toBe(2)
        ->and($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_COMPLETED)
        ->and($execution->fresh()->verified_at)->toBeNull();
});

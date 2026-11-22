<?php

use App\Models\AuditEvent;
use App\Models\SafeActionExecution;
use App\Models\SafeActionLateResult;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;

function createLateResultWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Late Result Workspace',
        'slug' => 'late-result-'.uniqid(),
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
        'name' => 'Late Result WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.0.0',
        'last_seen_at' => now()->subHour(),
    ]);

    return compact('owner', 'tenant', 'integration');
}

function makeLateResultSignedWhmcsRequest(
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
    $signature = hash_hmac('sha256', $canonical, (string) $integration->secret);

    return [
        'body' => $body,
        'headers' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => $signature,
            'HTTP_ACCEPT' => 'application/json',
        ],
    ];
}

function cancelAwaitingAgentExecution(User $owner, WhmcsIntegration $integration): SafeActionExecution
{
    test()->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'pull_and_run_diagnostic_collect',
        ]);

    $execution = SafeActionExecution::query()->firstOrFail();

    test()->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Queue a fresh diagnostic collect',
        ])
        ->assertRedirect();

    test()->actingAs($owner)
        ->post('/safe-actions/'.$execution->id.'/reject', [
            'rejection_reason' => 'Cancel before addon pickup',
        ])
        ->assertRedirect();

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_CANCELLED);

    return $execution;
}

it('stores every late addon result and keeps the latest on the execution row', function () {
    ['owner' => $owner, 'integration' => $integration] = createLateResultWorkspace();
    $execution = cancelAwaitingAgentExecution($owner, $integration);

    $path = '/api/v1/whmcs/safe-actions/'.$execution->id.'/result';

    $first = makeLateResultSignedWhmcsRequest($integration, 'POST', $path, [
        'status' => 'failed',
        'summary' => 'First late failure',
        'evidence' => ['remediation_applied' => false],
        'error_code' => 'addon_timeout',
    ]);
    $this->call('POST', $path, [], [], [], $first['headers'], $first['body'])
        ->assertOk()
        ->assertJsonPath('data.status', SafeActionExecution::STATUS_CANCELLED);

    $this->travel(2)->seconds();

    $second = makeLateResultSignedWhmcsRequest($integration, 'POST', $path, [
        'status' => 'success',
        'summary' => 'Second late success',
        'evidence' => ['remediation_applied' => true],
    ]);
    $this->call('POST', $path, [], [], [], $second['headers'], $second['body'])
        ->assertOk()
        ->assertJsonPath('data.status', SafeActionExecution::STATUS_CANCELLED);

    $execution->refresh();
    $lateResults = SafeActionLateResult::query()
        ->where('safe_action_execution_id', $execution->id)
        ->orderBy('received_at')
        ->orderBy('id')
        ->get();

    expect($execution->status)->toBe(SafeActionExecution::STATUS_CANCELLED)
        ->and($lateResults)->toHaveCount(2)
        ->and($lateResults[0]->status)->toBe('failed')
        ->and($lateResults[0]->payload_summary['summary'] ?? null)->toBe('First late failure')
        ->and($lateResults[1]->status)->toBe('success')
        ->and($lateResults[1]->payload_summary['summary'] ?? null)->toBe('Second late success')
        ->and($execution->late_result_status)->toBe('success')
        ->and($execution->late_result_at?->equalTo($lateResults[1]->received_at))->toBeTrue()
        ->and($execution->result['summary'] ?? null)->toBe('Second late success')
        ->and($execution->result['late'] ?? null)->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'safe_action.late_result')->count())->toBe(2)
        ->and(AuditEvent::query()->where('event_type', 'safe_action.completed')->count())->toBe(0);
});

it('lists the full late result history on the safe-action detail page', function () {
    $this->withoutVite();
    ['owner' => $owner, 'integration' => $integration] = createLateResultWorkspace();
    $execution = cancelAwaitingAgentExecution($owner, $integration);

    $path = '/api/v1/whmcs/safe-actions/'.$execution->id.'/result';
    foreach (['first late', 'second late'] as $summary) {
        $signed = makeLateResultSignedWhmcsRequest($integration, 'POST', $path, [
            'status' => 'success',
            'summary' => $summary,
            'evidence' => ['remediation_applied' => true],
        ]);
        $this->call('POST', $path, [], [], [], $signed['headers'], $signed['body'])->assertOk();
        $this->travel(1)->seconds();
    }

    $this->actingAs($owner)
        ->get(route('safe-actions.show', $execution))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('safe-actions/show')
            ->has('execution.late_results', 2)
            ->where('execution.late_results.0.summary', 'first late')
            ->where('execution.late_results.1.summary', 'second late')
            ->where('execution.late_result_status', 'success')
            ->where('execution.status', SafeActionExecution::STATUS_CANCELLED));
});

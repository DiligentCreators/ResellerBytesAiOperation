<?php

use App\Models\AuditEvent;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createSafeActionRejectWorkspace(array $entitlements = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Reject Workspace',
        'slug' => 'reject-'.uniqid(),
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
        'entitlements' => array_merge([
            'diagnostics.analysis' => true,
            'diagnostics.reanalyze' => true,
            'safe_actions.enabled' => true,
            'fixes.guided' => true,
            'fixes.one_click' => true,
            'fixes.bulk' => true,
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ], $entitlements),
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Reject WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.0.0',
        'last_seen_at' => now()->subHour(),
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => [
            'system' => [
                'whmcs_version' => '8.13.7',
                'error_display_disabled' => false,
            ],
        ],
        'payload_sha256' => str_repeat('c', 64),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

function makeRejectSignedWhmcsRequest(
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

it('allows an authorized operator to reject a pending safe action', function () {
    ['owner' => $owner, 'tenant' => $tenant, 'integration' => $integration, 'run' => $run] = createSafeActionRejectWorkspace();

    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'reject-finding'),
        'category' => 'security',
        'severity' => 'warning',
        'title' => 'PHP error display is not disabled',
        'summary' => 'display_errors is on',
        'evidence' => ['system.error_display_disabled' => false],
        'status' => 'open',
    ]);

    $this->actingAs($owner)->post('/safe-actions', [
        'integration_id' => $integration->id,
        'action_key' => 'health_version_ping',
        'finding_id' => $finding->id,
    ])->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();

    $this->actingAs($owner)
        ->post('/safe-actions/'.$execution->id.'/reject', [
            'rejection_reason' => 'Not needed this maintenance window',
        ])
        ->assertRedirect(route('safe-actions.show', $execution))
        ->assertSessionHas('status', 'Safe action rejected. The related finding stays open.');

    $execution->refresh();
    $finding->refresh();

    expect($execution->status)->toBe(SafeActionExecution::STATUS_REJECTED)
        ->and($execution->rejected_by_user_id)->toBe($owner->id)
        ->and($execution->rejected_at)->not->toBeNull()
        ->and($execution->rejection_reason)->toBe('Not needed this maintenance window')
        ->and($finding->status)->toBe('open');

    $audit = AuditEvent::query()->where('event_type', 'safe_action.rejected')->sole();
    expect($audit->actor_id)->toBe((string) $owner->id)
        ->and($audit->subject_id)->toBe($execution->id)
        ->and($audit->metadata['reason'])->toBe('Not needed this maintenance window')
        ->and($audit->occurred_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get('/safe-actions/'.$execution->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('safe-actions/show')
            ->where('execution.status', SafeActionExecution::STATUS_REJECTED)
            ->where('execution.rejector_name', $owner->name)
            ->where('execution.rejection_reason', 'Not needed this maintenance window')
            ->where('can_reject', false));
});

it('forbids viewers from rejecting safe actions', function () {
    ['owner' => $owner, 'tenant' => $tenant, 'integration' => $integration] = createSafeActionRejectWorkspace();
    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $viewer->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($owner)->post('/safe-actions', [
        'integration_id' => $integration->id,
        'action_key' => 'health_version_ping',
    ])->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();

    $this->actingAs($viewer)
        ->post('/safe-actions/'.$execution->id.'/reject', [
            'rejection_reason' => 'Viewer should not cancel this',
        ])
        ->assertForbidden();

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_PENDING_APPROVAL);
});

it('cancels awaiting-agent executions and removes them from the WHMCS pending feed', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionRejectWorkspace();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'pull_and_run_diagnostic_collect',
        ]);

    $execution = SafeActionExecution::query()->firstOrFail();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Queue a fresh diagnostic collect',
        ])
        ->assertRedirect();

    $execution->refresh();
    $integration->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT)
        ->and($integration->pending_diagnostic_collect_execution_id)->toBe($execution->id);

    $pendingBefore = makeRejectSignedWhmcsRequest($integration, 'GET', '/api/v1/whmcs/safe-actions/pending');
    $this->call('GET', '/api/v1/whmcs/safe-actions/pending', [], [], [], $pendingBefore['headers'], $pendingBefore['body'])
        ->assertOk()
        ->assertJsonPath('data.actions.0.id', $execution->id);

    $this->actingAs($owner)
        ->post('/safe-actions/'.$execution->id.'/reject', [
            'rejection_reason' => 'Cancel before addon pickup',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Safe action cancelled before the WHMCS addon could apply it.');

    $execution->refresh();
    $integration->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_CANCELLED)
        ->and($execution->rejected_by_user_id)->toBe($owner->id)
        ->and($integration->pending_diagnostic_collect_execution_id)->toBeNull();

    expect(AuditEvent::query()->where('event_type', 'safe_action.cancelled')->exists())->toBeTrue();

    $pendingAfter = makeRejectSignedWhmcsRequest($integration, 'GET', '/api/v1/whmcs/safe-actions/pending');
    $this->call('GET', '/api/v1/whmcs/safe-actions/pending', [], [], [], $pendingAfter['headers'], $pendingAfter['body'])
        ->assertOk()
        ->assertJsonPath('data.actions', []);

    $path = '/api/v1/whmcs/safe-actions/'.$execution->id.'/result';
    $result = makeRejectSignedWhmcsRequest($integration, 'POST', $path, [
        'status' => 'success',
        'summary' => 'Should be refused',
        'evidence' => ['remediation_applied' => true],
    ]);

    $this->call('POST', $path, [], [], [], $result['headers'], $result['body'])
        ->assertServerError();

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_CANCELLED);
});

it('refuses rejection of a completed execution', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionRejectWorkspace();

    $this->actingAs($owner)->post('/safe-actions', [
        'integration_id' => $integration->id,
        'action_key' => 'health_version_ping',
    ])->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();

    $this->actingAs($owner)->post('/safe-actions/'.$execution->id.'/approve', [
        'approval_reason' => 'Weekly health check before change window',
    ])->assertRedirect();

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_VERIFIED);

    $this->actingAs($owner)
        ->from(route('safe-actions.show', $execution))
        ->post('/safe-actions/'.$execution->id.'/reject', [
            'rejection_reason' => 'Too late',
        ])
        ->assertRedirect(route('safe-actions.show', $execution))
        ->assertSessionHasErrors('rejection_reason');

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_VERIFIED)
        ->and(AuditEvent::query()->where('event_type', 'safe_action.rejected')->count())->toBe(0);
});

it('returns 404 when another tenant tries to reject a safe action', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionRejectWorkspace();
    ['owner' => $otherOwner] = createSafeActionRejectWorkspace();

    $this->actingAs($owner)->post('/safe-actions', [
        'integration_id' => $integration->id,
        'action_key' => 'health_version_ping',
    ])->assertRedirect();

    $execution = SafeActionExecution::query()->where('integration_id', $integration->id)->firstOrFail();

    $this->actingAs($otherOwner)
        ->post('/safe-actions/'.$execution->id.'/reject', [
            'rejection_reason' => 'Cross tenant attempt',
        ])
        ->assertNotFound();

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_PENDING_APPROVAL);
});

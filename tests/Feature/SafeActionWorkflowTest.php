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
use Inertia\Testing\AssertableInertia as Assert;

function createSafeActionWorkspace(array $entitlements = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Safe Action Workspace',
        'slug' => 'safe-action-'.uniqid(),
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
        'name' => 'Safe Action WHMCS',
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
                'web_php_version' => '8.2.0',
                'configuration_file_writable' => true,
                'error_display_disabled' => true,
                'application_url_configured' => true,
            ],
            'php' => [
                'extensions' => ['curl' => true, 'soap' => false],
            ],
        ],
        'payload_sha256' => str_repeat('f', 64),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

it('lists allowlisted safe actions for entitled operators', function () {
    ['owner' => $owner] = createSafeActionWorkspace();

    $this->actingAs($owner)
        ->get('/safe-actions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('safe-actions/index')
            ->has('actions', 15)
            ->has('integrations', 1)
            ->has('executions.data'));
});

it('requests approves and verifies a health version ping', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionWorkspace();

    $this->actingAs($owner)
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'health_version_ping',
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_PENDING_APPROVAL);

    $this->actingAs($owner)
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Weekly health check before change window',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Safe action approved, executed, and audited.');

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_VERIFIED)
        ->and($execution->result['whmcs_version'])->toBe('8.13.7')
        ->and($execution->approved_by_user_id)->toBe($owner->id);
});

it('builds a permissions audit report from snapshot evidence', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionWorkspace();

    $this->actingAs($owner)->post('/safe-actions', [
        'integration_id' => $integration->id,
        'action_key' => 'permissions_audit_report',
    ]);

    $execution = SafeActionExecution::query()->firstOrFail();

    $this->actingAs($owner)->post('/safe-actions/'.$execution->id.'/approve', [
        'approval_reason' => 'Pre-change permissions audit',
    ])->assertRedirect();

    expect($execution->fresh()->result['warning_count'])->toBeGreaterThan(0);
});

it('generates whmcs admin deep links without secrets', function () {
    ['owner' => $owner, 'integration' => $integration, 'run' => $run, 'tenant' => $tenant] = createSafeActionWorkspace();
    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'deep-link'),
        'category' => 'security',
        'severity' => 'warning',
        'title' => 'Security setting drift',
        'summary' => 'Review security settings.',
        'evidence' => ['system.error_display_disabled' => true],
        'status' => 'open',
    ]);

    $this->actingAs($owner)->post('/safe-actions', [
        'integration_id' => $integration->id,
        'action_key' => 'whmcs_admin_deep_link',
        'finding_id' => $finding->id,
    ]);
    $execution = SafeActionExecution::query()->firstOrFail();
    $this->actingAs($owner)->post('/safe-actions/'.$execution->id.'/approve', [
        'approval_reason' => 'Open WHMCS admin path for manual fix',
    ]);

    $result = $execution->fresh()->result;
    expect($result['paths'])->not->toBeEmpty()
        ->and(json_encode($result))->not->toContain($integration->secret);
});

it('queues diagnostic collect for the WHMCS addon after approval', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionWorkspace();

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
            'approval_reason' => 'Need a fresh signed diagnostic after patching',
        ])
        ->assertRedirect()
        ->assertSessionHas(
            'status',
            'Safe action approved and queued. The WHMCS addon will run it on its next check-in; the result appears here.',
        );

    $execution->refresh();
    $integration->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT)
        ->and($integration->pending_diagnostic_collect_execution_id)->toBe($execution->id);

    app(SafeActionExecutor::class)->recordAddonResult(
        $execution,
        $integration,
        'success',
        'Diagnostic snapshot submitted by addon.',
        ['remediation_applied' => true, 'duplicate' => false],
    );

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_VERIFIED)
        ->and($integration->fresh()->pending_diagnostic_collect_at)->toBeNull();
});

it('rejects non-allowlisted action keys', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionWorkspace();

    $this->actingAs($owner)
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'drop_all_tables',
        ])
        ->assertSessionHasErrors('action_key');
});

it('rejects HTTP requests that try to set input.mode to auto', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionWorkspace();

    $this->actingAs($owner)
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'health_version_ping',
            'input' => ['mode' => 'auto'],
        ])
        ->assertSessionHasErrors('input.mode');

    expect(SafeActionExecution::query()->count())->toBe(0)
        ->and(SafeActionExecution::query()->where('mode', SafeActionExecution::MODE_AUTO)->count())->toBe(0);
});

it('blocks safe actions without plan entitlement', function () {
    ['owner' => $owner, 'integration' => $integration] = createSafeActionWorkspace([
        'safe_actions.enabled' => false,
    ]);

    $this->actingAs($owner)
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'health_version_ping',
        ])
        ->assertSessionHasErrors('action_key');
});

it('forbids viewers from requesting safe actions', function () {
    ['owner' => $owner, 'tenant' => $tenant, 'integration' => $integration] = createSafeActionWorkspace();
    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $viewer->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->post('/safe-actions', [
            'integration_id' => $integration->id,
            'action_key' => 'health_version_ping',
        ])
        ->assertForbidden();
});

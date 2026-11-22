<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\PlatformSettings;
use App\Services\SafeActions\SafeActionRegistry;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createBindingWorkspace(array $entitlements = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Binding Workspace',
        'slug' => 'binding-'.uniqid(),
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
        'name' => 'Binding WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
        'last_seen_at' => now()->subHour(),
    ]);

    $payload = [
        'system' => ['whmcs_version' => '8.13.7'],
        'whmcs' => [
            'services' => [
                'pending_paid_orders' => ['count' => 2, 'order_ids' => [301, 302]],
                'suspended_with_paid_invoices' => ['count' => 1, 'service_ids' => [201]],
            ],
            'billing' => [
                'overdue_without_reminder' => ['count' => 2, 'invoice_ids' => [401, 402]],
                'due_services_without_invoice' => ['count' => 2, 'service_ids' => [501, 502]],
            ],
        ],
    ];

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => $payload,
        'payload_sha256' => hash('sha256', json_encode($payload)),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

function createBindingFinding(array $workspace, string $issueKey, array $evidence = [], int $affectedCount = 0): DiagnosticFinding
{
    return DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', $issueKey.'|'.$workspace['run']->id.'|'.uniqid()),
        'issue_key' => $issueKey,
        'source' => 'rule',
        'affected_count' => $affectedCount,
        'fix_mode' => 'one_click',
        'category' => 'other',
        'severity' => 'warning',
        'title' => $issueKey,
        'summary' => $issueKey,
        'evidence' => $evidence,
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);
}

it('QA-RB-23 rejects order_accept_paid bound to a daily cron finding with crafted target_ids', function () {
    $workspace = createBindingWorkspace();
    $cronFinding = createBindingFinding($workspace, 'cron.daily_missed', [
        'whmcs.cron.last_daily_run_at' => now()->subDays(2)->toIso8601String(),
        'whmcs.cron.daily_starts_24h' => 0,
    ]);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'finding_id' => $cronFinding->id,
            'input' => ['target_ids' => [9999]],
        ])
        ->assertSessionHasErrors('finding_id');

    expect(SafeActionExecution::query()->count())->toBe(0);
});

it('QA-RB-23 rejects invoice_generate_due bound to an overdue-reminder finding', function () {
    $workspace = createBindingWorkspace();
    $overdueFinding = createBindingFinding($workspace, 'invoice.overdue_no_reminder', [
        'whmcs.billing.overdue_without_reminder.invoice_ids' => [401, 402],
    ], 2);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'invoice_generate_due',
            'finding_id' => $overdueFinding->id,
            'input' => ['target_ids' => [201]],
        ])
        ->assertSessionHasErrors('finding_id');

    expect(SafeActionExecution::query()->count())->toBe(0);
});

it('QA-RB-23 rejects target_ids outside the finding evidence affected set', function () {
    $workspace = createBindingWorkspace();
    $finding = createBindingFinding($workspace, 'orders.pending_paid', [
        'whmcs.services.pending_paid_orders.order_ids' => [301],
    ], 1);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [9999]],
        ])
        ->assertSessionHasErrors('input.target_ids');

    expect(SafeActionExecution::query()->count())->toBe(0);
});

it('QA-RB-23 rejects explicit target_ids when the finding has no affected ids', function () {
    $workspace = createBindingWorkspace();
    $finding = createBindingFinding($workspace, 'orders.pending_paid', [
        'whmcs.services.pending_paid_orders.count' => 2,
    ], 2);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [301]],
        ])
        ->assertSessionHasErrors('input.target_ids');

    expect(SafeActionExecution::query()->count())->toBe(0);
});

it('QA-RB-23 requires a finding for one-click actions that need targets', function () {
    $workspace = createBindingWorkspace();

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'input' => ['target_ids' => [301]],
        ])
        ->assertSessionHasErrors('finding_id');

    expect(SafeActionExecution::query()->count())->toBe(0);
});

it('QA-RB-23 rejects a finding from another integration', function () {
    $workspace = createBindingWorkspace();
    $other = createBindingWorkspace();
    $finding = createBindingFinding($other, 'orders.pending_paid', [
        'whmcs.services.pending_paid_orders.order_ids' => [301],
    ], 1);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [301]],
        ])
        ->assertNotFound();

    expect(SafeActionExecution::query()->count())->toBe(0);
});

it('QA-RB-24 ignores client messagename and stores the server-resolved template on the execution', function () {
    $workspace = createBindingWorkspace();
    app(PlatformSettings::class)->set(
        'fixes.overdue_reminder_messagename',
        'First Invoice Overdue Notice',
    );
    $finding = createBindingFinding($workspace, 'invoice.overdue_no_reminder', [
        'whmcs.billing.overdue_without_reminder.invoice_ids' => [401],
    ], 1);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'invoice_send_overdue_reminder',
            'finding_id' => $finding->id,
            'input' => [
                'target_ids' => [401],
                'messagename' => 'Crafted Evil Template',
                'params' => ['messagename' => 'Crafted Evil Template'],
            ],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();
    expect($execution->resolved_params['messagename'] ?? null)->toBe('First Invoice Overdue Notice')
        ->and($execution->preview['items'][0]['params']['messagename'] ?? null)->toBe('First Invoice Overdue Notice')
        ->and($execution->input)->not->toHaveKey('messagename')
        ->and($execution->input)->not->toHaveKey('params')
        ->and($execution->requires_password_confirm)->toBeTrue();
});

it('QA-RB-24 requires password confirm to approve SendEmail overdue reminder', function () {
    $workspace = createBindingWorkspace();
    $finding = createBindingFinding($workspace, 'invoice.overdue_no_reminder', [
        'whmcs.billing.overdue_without_reminder.invoice_ids' => [401],
    ], 1);

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'invoice_send_overdue_reminder',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [401]],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();
    expect(app(SafeActionRegistry::class)->get('invoice_send_overdue_reminder')['requires_password_confirm'])->toBeTrue();

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Send overdue reminder after review',
        ])
        ->assertRedirect(route('password.confirm'));
});

it('QA-RB-24 pending payload includes resolved_params messagename matching the preview', function () {
    $workspace = createBindingWorkspace();
    $finding = createBindingFinding($workspace, 'invoice.overdue_no_reminder', [
        'whmcs.billing.overdue_without_reminder.invoice_ids' => [401],
    ], 1);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'invoice_send_overdue_reminder',
            'finding_id' => $finding->id,
            'input' => [
                'target_ids' => [401],
                'messagename' => 'Should Be Ignored',
            ],
        ]);

    $execution = SafeActionExecution::query()->firstOrFail();
    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Send overdue reminder',
        ])
        ->assertRedirect();

    $path = '/api/v1/whmcs/safe-actions/pending';
    $body = '';
    $timestamp = now()->timestamp;
    $nonce = Str::random(64);
    $canonical = implode(chr(10), [
        'GET',
        $path,
        (string) $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);

    $this->call('GET', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_RB_INTEGRATION' => $workspace['integration']->id,
        'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_RB_NONCE' => $nonce,
        'HTTP_X_RB_SIGNATURE' => hash_hmac('sha256', $canonical, $workspace['integration']->secret),
    ], $body)
        ->assertOk()
        ->assertJsonPath('data.actions.0.resolved_params.messagename', 'First Invoice Overdue Notice')
        ->assertJsonPath('data.actions.0.preview.items.0.params.messagename', 'First Invoice Overdue Notice');
});

it('QA-RB-25 refuses store and approve when fixes.enabled is false', function () {
    $workspace = createBindingWorkspace();
    app(PlatformSettings::class)->set('fixes.enabled', false);
    $finding = createBindingFinding($workspace, 'orders.pending_paid', [
        'whmcs.services.pending_paid_orders.order_ids' => [301],
    ], 1);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [301]],
        ])
        ->assertSessionHasErrors([
            'action_key' => SafeActionRegistry::FIXES_DISABLED_MESSAGE,
        ]);

    app(PlatformSettings::class)->set('fixes.enabled', true);
    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [301]],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();
    app(PlatformSettings::class)->set('fixes.enabled', false);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Accept paid order',
        ])
        ->assertSessionHasErrors([
            'action_key' => SafeActionRegistry::FIXES_DISABLED_MESSAGE,
        ]);

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_PENDING_APPROVAL);
});

it('QA-RB-25 Fix Center shows fixes unavailable while guided steps remain', function () {
    $workspace = createBindingWorkspace();
    app(PlatformSettings::class)->set('fixes.enabled', false);
    createBindingFinding($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201],
    ], 1);
    createBindingFinding($workspace, 'cron.not_running', [
        'whmcs.cron.last_daily_run_at' => null,
    ], 0);

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->where('fixes_enabled', false)
            ->where('fixes_unavailable_message', SafeActionRegistry::FIXES_DISABLED_MESSAGE)
            ->where('entitlements.fixes_one_click', false)
            ->where('entitlements.fixes_guided', true)
            ->where('issues.data.0.can_one_click', false)
            ->where('issues.data.0.fixes_unavailable', true)
            ->where('issues.data.0.can_guide', true)
            ->where('issues.data.0.primary_action', 'start_guide'));
});

it('still allows non-fix safe actions when fixes.enabled is false', function () {
    $workspace = createBindingWorkspace();
    app(PlatformSettings::class)->set('fixes.enabled', false);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'health_version_ping',
        ])
        ->assertRedirect();

    expect(SafeActionExecution::query()->where('action_key', 'health_version_ping')->exists())->toBeTrue();
});

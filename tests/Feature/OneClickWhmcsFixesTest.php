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
use App\Services\SafeActions\SafeActionRegistry;
use Illuminate\Support\Str;
use InvalidArgumentException;

function createOneClickWorkspace(array $entitlements = [], array $payloadExtra = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'One Click Workspace',
        'slug' => 'one-click-'.uniqid(),
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
        'name' => 'One Click WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
        'last_seen_at' => now()->subHour(),
    ]);

    $payload = array_replace_recursive([
        'system' => [
            'whmcs_version' => '8.13.7',
            'web_php_version' => '8.2.0',
            'configuration_file_writable' => false,
            'error_display_disabled' => true,
            'application_url_configured' => true,
        ],
        'whmcs' => [
            'module_queue' => [
                'source' => 'api',
                'pending_count' => 2,
                'oldest_age_minutes' => 90,
                'by_action' => ['create' => 0, 'suspend' => 0, 'unsuspend' => 1, 'terminate' => 1, 'other' => 0],
                'items' => [
                    ['id' => 11, 'service_type' => 'service', 'service_id' => 101, 'action' => 'unsuspend', 'age_minutes' => 90, 'retries' => 2, 'error' => 'timeout'],
                    ['id' => 12, 'service_type' => 'service', 'service_id' => 102, 'action' => 'terminate', 'age_minutes' => 80, 'retries' => 1, 'error' => 'refused'],
                ],
            ],
            'services' => [
                'suspended_with_paid_invoices' => ['count' => 2, 'service_ids' => [201, 202]],
                'pending_paid_orders' => ['count' => 2, 'order_ids' => [301, 302], 'oldest_age_minutes' => 40],
            ],
            'billing' => [
                'overdue_without_reminder' => ['count' => 2, 'invoice_ids' => [401, 402]],
                'due_services_without_invoice' => ['count' => 2, 'service_ids' => [501, 502]],
            ],
            'tickets' => [
                'threshold_hours' => 24,
                'sla_breach' => ['count' => 2, 'ticket_ids' => [601, 602], 'oldest_hours' => 30],
            ],
            'domains' => [
                'renewal_failed_7d' => ['count' => 1, 'domain_ids' => [701]],
            ],
        ],
    ], $payloadExtra);

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

function createFindingForIssue(array $workspace, string $issueKey, array $evidence, int $affectedCount = 1): DiagnosticFinding
{
    return DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', $issueKey.'|'.$workspace['run']->id),
        'issue_key' => $issueKey,
        'source' => 'rule',
        'affected_count' => $affectedCount,
        'fix_mode' => 'one_click',
        'category' => 'other',
        'severity' => 'warning',
        'title' => $issueKey,
        'summary' => $issueKey,
        'evidence' => $evidence,
        'status' => 'open',
    ]);
}

it('allowlists one-click LocalAPI actions and refuses forbidden commands', function () {
    $registry = app(SafeActionRegistry::class);

    foreach ([
        'module_queue_retry',
        'service_unsuspend_paid',
        'invoice_send_overdue_reminder',
        'invoice_generate_due',
        'order_accept_paid',
        'ticket_escalate_sla',
        'domain_renew_retry',
        'whmcs_clear_template_cache',
    ] as $key) {
        expect($registry->isAllowlisted($key))->toBeTrue();
        $definition = $registry->get($key);
        expect($definition['execution_target'])->toBe('addon')
            ->and($definition['entitlement'])->toBe('fixes.one_click');
    }

    $forbidden = $registry->forbiddenLocalApiCommands();
    $allCommands = collect($registry->all())
        ->flatMap(fn (array $definition): array => $definition['local_api_commands'])
        ->unique()
        ->all();

    foreach ($forbidden as $command) {
        expect($allCommands)->not->toContain($command);
    }

    expect($allCommands)->not->toContain('ModuleTerminate')
        ->and(implode(',', $allCommands))->not->toContain('Refund')
        ->and(implode(',', $allCommands))->not->toContain('Upgrade');
});

it('requires fixes.one_click entitlement for one-click remediations', function () {
    $workspace = createOneClickWorkspace(['fixes.one_click' => false]);
    $finding = createFindingForIssue($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201],
    ]);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'service_unsuspend_paid',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [201]],
        ])
        ->assertSessionHasErrors('action_key');
});

it('builds preview and stores target ids before approval', function () {
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201, 202],
    ], 2);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'service_unsuspend_paid',
            'finding_id' => $finding->id,
            'input' => [
                'mode' => 'one_click',
                'target_ids' => [201],
            ],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_PENDING_APPROVAL)
        ->and($execution->preview['items'][0]['command'])->toBe('ModuleUnsuspend')
        ->and($execution->preview['items'][0]['params']['serviceid'])->toBe(201)
        ->and($execution->target_ids)->toBe([201])
        ->and($execution->issue_key)->toBe('service.suspended_but_paid')
        ->and($execution->items_total)->toBe(1);
});

it('requires password confirm for medium risk one-click approval', function () {
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'tickets.sla_breach', [
        'whmcs.tickets.sla_breach.ticket_ids' => [601],
    ]);

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'ticket_escalate_sla',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [601]],
        ])
        ->assertRedirect();

    $low = SafeActionExecution::query()->where('action_key', 'ticket_escalate_sla')->firstOrFail();
    $this->actingAs($workspace['owner'])
        ->post('/safe-actions/'.$low->id.'/approve', [
            'approval_reason' => 'Escalate overdue ticket to High',
        ])
        ->assertRedirect();
    expect($low->fresh()->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT);

    $paidFinding = createFindingForIssue($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201],
    ]);
    $this->actingAs($workspace['owner'])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'service_unsuspend_paid',
            'finding_id' => $paidFinding->id,
            'input' => ['target_ids' => [201]],
        ]);
    $medium = SafeActionExecution::query()->where('action_key', 'service_unsuspend_paid')->firstOrFail();

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions/'.$medium->id.'/approve', [
            'approval_reason' => 'Unsuspend paid service after review',
        ])
        ->assertRedirect(route('password.confirm'));
});

it('enforces bulk cap and bulk entitlement', function () {
    config(['fixes.bulk_max_items' => 2]);
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201, 202, 203],
    ], 3);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'service_unsuspend_paid',
            'finding_id' => $finding->id,
            'input' => [
                'mode' => 'bulk',
                'target_ids' => [201, 202, 203],
            ],
        ])
        ->assertSessionHasErrors('action_key');

    $starter = createOneClickWorkspace(['fixes.bulk' => false]);
    $starterFinding = createFindingForIssue($starter, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201, 202],
    ], 2);

    $this->actingAs($starter['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $starter['integration']->id,
            'action_key' => 'service_unsuspend_paid',
            'finding_id' => $starterFinding->id,
            'input' => [
                'mode' => 'bulk',
                'target_ids' => [201, 202],
            ],
        ])
        ->assertSessionHasErrors('input.target_ids');
});

it('refuses terminate module queue items on the server preview', function () {
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'module.queue_failed', [
        'whmcs.module_queue.failed_item_ids' => [12],
    ]);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'module_queue_retry',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [12]],
        ])
        ->assertSessionHasErrors('action_key');
});

it('allows manual one-click module_queue_retry for unsuspend with password confirm', function () {
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'module.queue_failed', [
        'whmcs.module_queue.failed_item_ids' => [11],
    ]);

    $definition = app(SafeActionRegistry::class)->get('module_queue_retry');
    expect($definition['requires_password_confirm'])->toBeTrue();

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'module_queue_retry',
            'finding_id' => $finding->id,
            'input' => [
                'mode' => 'one_click',
                'target_ids' => [11],
            ],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->where('action_key', 'module_queue_retry')->firstOrFail();
    expect($execution->mode)->toBe(SafeActionExecution::MODE_ONE_CLICK)
        ->and($execution->requires_password_confirm)->toBeTrue()
        ->and($execution->preview['items'][0]['command'])->toBe('ModuleUnsuspend')
        ->and($execution->preview['items'][0]['params']['serviceid'])->toBe(101);

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Retry unsuspend queue item after review',
        ])
        ->assertRedirect(route('password.confirm'));
});

it('refuses create suspend and unsuspend module queue items when building an auto preview', function () {
    $workspace = createOneClickWorkspace([], [
        'whmcs' => [
            'module_queue' => [
                'source' => 'api',
                'pending_count' => 3,
                'oldest_age_minutes' => 90,
                'by_action' => ['create' => 1, 'suspend' => 1, 'unsuspend' => 1, 'terminate' => 0, 'other' => 0],
                'items' => [
                    ['id' => 31, 'service_type' => 'service', 'service_id' => 301, 'action' => 'unsuspend', 'age_minutes' => 90, 'retries' => 1, 'error' => 'timeout'],
                    ['id' => 32, 'service_type' => 'service', 'service_id' => 302, 'action' => 'suspend', 'age_minutes' => 80, 'retries' => 1, 'error' => 'timeout'],
                    ['id' => 34, 'service_type' => 'service', 'service_id' => 304, 'action' => 'create', 'age_minutes' => 60, 'retries' => 1, 'error' => 'timeout'],
                ],
            ],
        ],
    ]);
    $finding = createFindingForIssue($workspace, 'module.queue_failed', [
        'whmcs.module_queue.failed_item_ids' => [31, 32, 34],
    ], 3);

    expect(fn () => app(SafeActionExecutor::class)->request(
        $workspace['integration'],
        $workspace['owner'],
        'module_queue_retry',
        ['mode' => SafeActionExecution::MODE_AUTO, 'target_ids' => [31, 32, 34]],
        $finding,
    ))->toThrow(InvalidArgumentException::class, 'No auto-safe targets remain after preview validation.');
});

it('skips fraud orders in preview for order_accept_paid executable set from snapshot ids only', function () {
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'orders.pending_paid', [
        'whmcs.services.pending_paid_orders.order_ids' => [301],
    ]);

    $this->actingAs($workspace['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'order_accept_paid',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [301]],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->where('action_key', 'order_accept_paid')->firstOrFail();
    expect($execution->preview['items'][0]['command'])->toBe('AcceptOrder')
        ->and($execution->preview['items'][0]['params']['orderid'])->toBe(301);
});

it('records minutes_saved from addon item results and queues verify collect', function () {
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'tickets.sla_breach', [
        'whmcs.tickets.sla_breach.ticket_ids' => [601, 602],
    ], 2);

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'ticket_escalate_sla',
            'finding_id' => $finding->id,
            'input' => [
                'mode' => 'bulk',
                'target_ids' => [601, 602],
            ],
        ])
        ->assertRedirect();

    $execution = SafeActionExecution::query()->firstOrFail();
    expect($execution->mode)->toBe(SafeActionExecution::MODE_BULK)
        ->and($execution->batch_id)->not->toBeNull()
        ->and($execution->items_total)->toBe(2);

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Escalate both SLA tickets',
        ])
        ->assertRedirect();

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT);

    app(SafeActionExecutor::class)->recordAddonResult(
        $execution,
        $workspace['integration'],
        'success',
        'ticket_escalate_sla: 2 succeeded, 0 failed of 2.',
        [
            'remediation_applied' => true,
            'item_results' => [
                ['id' => 601, 'status' => 'success'],
                ['id' => 602, 'status' => 'success'],
            ],
        ],
    );

    $execution->refresh();
    expect($execution->status)->toBe(SafeActionExecution::STATUS_COMPLETED)
        ->and($execution->items_succeeded)->toBe(2)
        ->and($execution->minutes_saved)->toBe(40)
        ->and($workspace['integration']->fresh()->pending_diagnostic_collect_execution_id)->toBe($execution->id);
});

it('marks verified when a later snapshot clears the finding', function () {
    $workspace = createOneClickWorkspace();
    $finding = createFindingForIssue($workspace, 'tickets.sla_breach', [
        'whmcs.tickets.sla_breach.ticket_ids' => [601],
    ]);

    $this->actingAs($workspace['owner'])
        ->post('/safe-actions', [
            'integration_id' => $workspace['integration']->id,
            'action_key' => 'ticket_escalate_sla',
            'finding_id' => $finding->id,
            'input' => ['target_ids' => [601]],
        ]);
    $execution = SafeActionExecution::query()->firstOrFail();
    $this->actingAs($workspace['owner'])
        ->post('/safe-actions/'.$execution->id.'/approve', [
            'approval_reason' => 'Escalate ticket',
        ]);

    app(SafeActionExecutor::class)->recordAddonResult(
        $execution->fresh(),
        $workspace['integration']->fresh(),
        'success',
        'ok',
        [
            'remediation_applied' => true,
            'item_results' => [['id' => 601, 'status' => 'success']],
        ],
    );

    $verifyRun = DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('a', 64),
        'analysis_summary' => 'clean',
    ]);

    app(SafeActionExecutor::class)->verifyCollectFromSnapshot(
        $workspace['integration']->fresh(),
        $verifyRun,
    );

    expect($execution->fresh()->status)->toBe(SafeActionExecution::STATUS_VERIFIED)
        ->and($execution->fresh()->verified_at)->not->toBeNull();
});

it('shows upgrade hint props for starter without one-click entitlement', function () {
    $workspace = createOneClickWorkspace([
        'safe_actions.enabled' => false,
        'fixes.one_click' => false,
        'fixes.bulk' => false,
    ]);
    $finding = createFindingForIssue($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201],
    ]);

    $this->actingAs($workspace['owner'])
        ->get('/findings/'.$finding->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('findings/show')
            ->where('fixes_one_click_enabled', false)
            ->has('suggested_safe_actions')
            ->where('suggested_safe_actions.0.can_one_click', false)
            ->where('suggested_safe_actions.0.upgrade_hint', 'Upgrade to Growth to fix in one click. Guided steps remain available below.'));
});

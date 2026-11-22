<?php

use App\Events\FixActionAutoQueued;
use App\Models\AutoFixPolicy;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantNotification;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\PlatformSettings;
use App\Services\SafeActions\AutoFixRunner;
use App\Services\SafeActions\SafeActionExecutor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @param  array<string, mixed>  $entitlements
 * @param  array<string, mixed>  $payloadExtra
 * @return array{
 *     owner: User,
 *     tenant: Tenant,
 *     integration: WhmcsIntegration,
 *     run: DiagnosticRun
 * }
 */
function createAutoFixRunnerWorkspace(array $entitlements = [], array $payloadExtra = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Auto Fix Runner Workspace',
        'slug' => 'auto-fix-runner-'.uniqid(),
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
        'plan_code' => 'business',
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 14900,
        'entitlements' => array_merge([
            'diagnostics.analysis' => true,
            'safe_actions.enabled' => true,
            'fixes.guided' => true,
            'fixes.one_click' => true,
            'fixes.bulk' => true,
            'fixes.auto' => true,
            'alerts.channels' => true,
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ], $entitlements),
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Runner WHMCS',
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
            'billing' => [
                'overdue_without_reminder' => ['count' => 2, 'invoice_ids' => [401, 402]],
            ],
            'tickets' => [
                'threshold_hours' => 24,
                'sla_breach' => ['count' => 2, 'ticket_ids' => [601, 602], 'oldest_hours' => 30],
            ],
            'module_queue' => [
                'source' => 'api',
                'pending_count' => 1,
                'oldest_age_minutes' => 90,
                'by_action' => ['create' => 0, 'suspend' => 1, 'unsuspend' => 0, 'terminate' => 0, 'other' => 0],
                'items' => [
                    ['id' => 11, 'service_type' => 'service', 'service_id' => 101, 'action' => 'suspend', 'age_minutes' => 90, 'retries' => 2, 'error' => 'timeout'],
                ],
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

function createOpenFinding(array $workspace, string $issueKey, array $evidence, int $affectedCount = 1): DiagnosticFinding
{
    return DiagnosticFinding::create([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', $issueKey.'|'.$workspace['run']->id.'|'.uniqid()),
        'issue_key' => $issueKey,
        'source' => 'rule',
        'affected_count' => $affectedCount,
        'fix_mode' => 'one_click',
        'category' => 'billing',
        'severity' => 'high',
        'title' => $issueKey,
        'summary' => $issueKey,
        'evidence' => $evidence,
        'confidence' => 1,
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);
}

it('queues an auto execution with mode auto when a matching policy is enabled', function () {
    Event::fake([FixActionAutoQueued::class]);

    $workspace = createAutoFixRunnerWorkspace();
    $policy = AutoFixPolicy::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => null,
        'action_key' => 'invoice_send_overdue_reminder',
        'enabled' => true,
        'daily_cap' => null,
        'created_by_user_id' => $workspace['owner']->id,
    ]);

    $finding = createOpenFinding($workspace, 'invoice.overdue_no_reminder', [
        'whmcs.billing.overdue_without_reminder.invoice_ids' => [401, 402],
        'whmcs.billing.overdue_without_reminder.count' => 2,
    ], 2);

    $queued = app(AutoFixRunner::class)->processFindings($workspace['run'], [$finding]);

    expect($queued)->toHaveCount(1);
    $execution = $queued[0];
    expect($execution->mode)->toBe(SafeActionExecution::MODE_AUTO)
        ->and($execution->status)->toBe(SafeActionExecution::STATUS_AWAITING_AGENT)
        ->and($execution->action_key)->toBe('invoice_send_overdue_reminder')
        ->and($execution->approved_by_user_id)->toBe($workspace['owner']->id)
        ->and($execution->input['auto_fix_policy_id'] ?? null)->toBe($policy->id)
        ->and($execution->input['mode'] ?? null)->toBe('auto');

    Event::assertDispatched(FixActionAutoQueued::class, function (FixActionAutoQueued $event) use ($execution, $policy): bool {
        return $event->executionId === $execution->id
            && $event->policyId === $policy->id;
    });

    expect(TenantNotification::query()
        ->where('tenant_id', $workspace['tenant']->id)
        ->where('event_type', 'fix.auto_queued')
        ->exists())->toBeTrue();
});

it('does not auto-queue when the platform kill switch is off', function () {
    $workspace = createAutoFixRunnerWorkspace();
    AutoFixPolicy::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => null,
        'action_key' => 'ticket_escalate_sla',
        'enabled' => true,
        'daily_cap' => null,
        'created_by_user_id' => $workspace['owner']->id,
    ]);
    $finding = createOpenFinding($workspace, 'tickets.sla_breach', [
        'whmcs.tickets.sla_breach.ticket_ids' => [601, 602],
        'whmcs.tickets.sla_breach.count' => 2,
    ], 2);

    app(PlatformSettings::class)->set('fixes.enabled', false);

    $queued = app(AutoFixRunner::class)->processFindings($workspace['run'], [$finding]);

    expect($queued)->toBe([])
        ->and(SafeActionExecution::query()->where('mode', SafeActionExecution::MODE_AUTO)->count())->toBe(0);
});

it('respects the platform auto-fix daily cap', function () {
    $workspace = createAutoFixRunnerWorkspace();
    AutoFixPolicy::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => null,
        'action_key' => 'ticket_escalate_sla',
        'enabled' => true,
        'daily_cap' => null,
        'created_by_user_id' => $workspace['owner']->id,
    ]);
    $finding = createOpenFinding($workspace, 'tickets.sla_breach', [
        'whmcs.tickets.sla_breach.ticket_ids' => [601],
        'whmcs.tickets.sla_breach.count' => 1,
    ]);

    app(PlatformSettings::class)->set('fixes.auto_fix_daily_cap', 1);

    SafeActionExecution::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'whmcs_clear_template_cache',
        'mode' => SafeActionExecution::MODE_AUTO,
        'status' => SafeActionExecution::STATUS_AWAITING_AGENT,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'requires_password_confirm' => false,
        'input' => ['mode' => 'auto'],
        'requested_at' => now(),
    ]);

    $queued = app(AutoFixRunner::class)->processFindings($workspace['run'], [$finding]);

    expect($queued)->toBe([])
        ->and(SafeActionExecution::query()
            ->where('action_key', 'ticket_escalate_sla')
            ->where('mode', SafeActionExecution::MODE_AUTO)
            ->count())->toBe(0);
});

it('respects a per-policy daily cap', function () {
    $workspace = createAutoFixRunnerWorkspace();
    $policy = AutoFixPolicy::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => null,
        'action_key' => 'ticket_escalate_sla',
        'enabled' => true,
        'daily_cap' => 1,
        'created_by_user_id' => $workspace['owner']->id,
    ]);
    $finding = createOpenFinding($workspace, 'tickets.sla_breach', [
        'whmcs.tickets.sla_breach.ticket_ids' => [601],
        'whmcs.tickets.sla_breach.count' => 1,
    ]);

    SafeActionExecution::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'ticket_escalate_sla',
        'mode' => SafeActionExecution::MODE_AUTO,
        'status' => SafeActionExecution::STATUS_AWAITING_AGENT,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'requires_password_confirm' => false,
        'input' => ['mode' => 'auto', 'auto_fix_policy_id' => $policy->id],
        'requested_at' => now(),
    ]);

    $queued = app(AutoFixRunner::class)->processFindings($workspace['run'], [$finding]);

    expect($queued)->toBe([])
        ->and(SafeActionExecution::query()
            ->where('action_key', 'ticket_escalate_sla')
            ->where('mode', SafeActionExecution::MODE_AUTO)
            ->count())->toBe(1);
});

it('never auto-queues forbidden action classes even when forced through the executor', function () {
    $workspace = createAutoFixRunnerWorkspace();
    $finding = createOpenFinding($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201],
        'whmcs.services.suspended_with_paid_invoices.count' => 1,
    ]);

    expect(fn () => app(SafeActionExecutor::class)->request(
        $workspace['integration'],
        $workspace['owner'],
        'service_unsuspend_paid',
        ['mode' => SafeActionExecution::MODE_AUTO],
        $finding,
    ))->toThrow(InvalidArgumentException::class, 'This safe action does not allow auto mode.');
});

it('skips findings marked never_auto in the issue catalog', function () {
    $workspace = createAutoFixRunnerWorkspace();
    AutoFixPolicy::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => null,
        'action_key' => 'service_unsuspend_paid',
        'enabled' => true,
        'daily_cap' => null,
        'created_by_user_id' => $workspace['owner']->id,
    ]);

    // Policy insert for a non-auto action should not be creatable via HTTP,
    // but even if a row exists, never_auto findings must not run.
    $finding = createOpenFinding($workspace, 'service.suspended_but_paid', [
        'whmcs.services.suspended_with_paid_invoices.service_ids' => [201],
        'whmcs.services.suspended_with_paid_invoices.count' => 1,
    ]);

    $queued = app(AutoFixRunner::class)->processFindings($workspace['run'], [$finding]);

    expect($queued)->toBe([])
        ->and(SafeActionExecution::query()->where('mode', SafeActionExecution::MODE_AUTO)->count())->toBe(0);
});

it('does not run without the fixes.auto entitlement', function () {
    $workspace = createAutoFixRunnerWorkspace(['fixes.auto' => false]);
    AutoFixPolicy::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => null,
        'action_key' => 'invoice_send_overdue_reminder',
        'enabled' => true,
        'daily_cap' => null,
        'created_by_user_id' => $workspace['owner']->id,
    ]);
    $finding = createOpenFinding($workspace, 'invoice.overdue_no_reminder', [
        'whmcs.billing.overdue_without_reminder.invoice_ids' => [401],
        'whmcs.billing.overdue_without_reminder.count' => 1,
    ]);

    $queued = app(AutoFixRunner::class)->processFindings($workspace['run'], [$finding]);

    expect($queued)->toBe([]);
});

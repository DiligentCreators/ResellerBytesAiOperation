<?php

use App\Models\SafeActionExecution;
use App\Models\SafeActionLateResult;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Carbon\CarbonImmutable;

function createFixLogWorkspace(string $installName = 'Fix Log WHMCS'): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Fix Log Workspace',
        'slug' => 'fix-log-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => $installName,
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return compact('owner', 'tenant', 'integration');
}

it('streams a tenant-scoped fix log csv with expected columns and no secrets', function () {
    $a = createFixLogWorkspace('Alpha WHMCS');
    $b = createFixLogWorkspace('Bravo WHMCS');

    SafeActionExecution::create([
        'tenant_id' => $a['tenant']->id,
        'integration_id' => $a['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'issue_key' => 'billing.suspended_paid',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_VERIFIED,
        'risk_level' => 'low',
        'requested_by_user_id' => $a['owner']->id,
        'approved_by_user_id' => $a['owner']->id,
        'minutes_saved' => 45,
        'requested_at' => now()->subHour(),
        'input' => ['secret_should_not_appear' => 'nope'],
        'result' => ['ok' => true],
    ]);

    SafeActionExecution::create([
        'tenant_id' => $b['tenant']->id,
        'integration_id' => $b['integration']->id,
        'action_key' => 'other_tenant_action',
        'mode' => SafeActionExecution::MODE_BULK,
        'status' => SafeActionExecution::STATUS_VERIFIED,
        'risk_level' => 'low',
        'requested_by_user_id' => $b['owner']->id,
        'minutes_saved' => 10,
        'requested_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($a['owner'])
        ->get(route('reports.fix-log.export', $a['tenant']));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(preg_split("/\r\n|\n|\r/", trim($csv)) ?: []));

    expect($lines[0])->toBe('date,installation,issue,mode,decision,decided_by,decided_at,decision_reason,approved_by,rejected_by,rejected_at,rejection_reason,result,minutes_saved,late_result_at,late_result_count');
    expect($csv)->toContain('Alpha WHMCS')
        ->and($csv)->toContain('one_click')
        ->and($csv)->toContain('45')
        ->and($csv)->toContain('Approved by')
        ->and($csv)->not->toContain('secret_should_not_appear')
        ->and($csv)->not->toContain('other_tenant_action')
        ->and($csv)->not->toContain('Bravo WHMCS');
});

it('presents rejected and cancelled fix log rows with rejector date and reason', function () {
    $this->withoutVite();
    $workspace = createFixLogWorkspace('Reject Log WHMCS');
    $rejector = $workspace['owner'];
    $rejectedAt = now()->subMinutes(12);

    SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'issue_key' => 'billing.suspended_paid',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_REJECTED,
        'risk_level' => 'low',
        'requested_by_user_id' => $rejector->id,
        'rejected_by_user_id' => $rejector->id,
        'rejection_reason' => 'Not needed this maintenance window',
        'requested_at' => now()->subHour(),
        'rejected_at' => $rejectedAt,
    ]);

    SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'issue_key' => 'billing.suspended_paid',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_CANCELLED,
        'risk_level' => 'low',
        'requested_by_user_id' => $rejector->id,
        'rejected_by_user_id' => $rejector->id,
        'rejection_reason' => 'Cancel before addon pickup',
        'requested_at' => now()->subHours(2),
        'rejected_at' => now()->subMinutes(30),
    ]);

    $this->actingAs($rejector)
        ->get(route('reports.fix-log.print', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/fix-log-print')
            ->has('rows', 2)
            ->where('rows.0.decision_label', 'Rejected by')
            ->where('rows.0.decided_by', $rejector->name)
            ->where('rows.0.approved_by', null)
            ->where('rows.0.rejected_by', $rejector->name)
            ->where('rows.0.rejection_reason', 'Not needed this maintenance window')
            ->where('rows.0.date', $rejectedAt->toIso8601String())
            ->where('rows.1.decision_label', 'Cancelled by')
            ->where('rows.1.rejected_by', $rejector->name)
            ->where('rows.1.rejection_reason', 'Cancel before addon pickup'));

    $csv = $this->actingAs($rejector)
        ->get(route('reports.fix-log.export', $workspace['tenant']))
        ->assertOk()
        ->streamedContent();

    $lines = array_values(array_filter(preg_split("/\r\n|\n|\r/", trim($csv)) ?: []));
    expect($lines[0])->toBe('date,installation,issue,mode,decision,decided_by,decided_at,decision_reason,approved_by,rejected_by,rejected_at,rejection_reason,result,minutes_saved,late_result_at,late_result_count');
    expect($csv)->toContain($rejector->name)
        ->and($csv)->toContain('Not needed this maintenance window')
        ->and($csv)->toContain('Cancel before addon pickup')
        ->and($csv)->toContain('Rejected by')
        ->and($csv)->toContain('Cancelled by')
        ->and($csv)->toContain('rejected')
        ->and($csv)->toContain('cancelled');
});

it('shows plural late addon results with tenant-local latest time in ui print and csv', function () {
    $this->withoutVite();
    $workspace = createFixLogWorkspace('Late Fix Log WHMCS');
    $workspace['tenant']->forceFill(['timezone' => 'America/New_York'])->save();
    $rejector = $workspace['owner'];
    // 2026-11-22 14:37 UTC → Nov 22, 9:37 AM in America/New_York
    $lateAt = CarbonImmutable::parse('2026-11-22T14:37:00Z');

    $execution = SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'pull_and_run_diagnostic_collect',
        'issue_key' => 'diagnostics.stale',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_CANCELLED,
        'risk_level' => 'low',
        'requested_by_user_id' => $rejector->id,
        'rejected_by_user_id' => $rejector->id,
        'rejection_reason' => 'Cancel before addon pickup',
        'requested_at' => now()->subHours(2),
        'rejected_at' => now()->subHour(),
        'late_result_status' => 'failed',
        'late_result_at' => $lateAt,
        'result' => [
            'summary' => 'Addon finished after cancel',
            'late' => true,
            'addon_status' => 'failed',
        ],
    ]);

    SafeActionLateResult::create([
        'tenant_id' => $workspace['tenant']->id,
        'safe_action_execution_id' => $execution->id,
        'status' => 'failed',
        'payload_summary' => ['summary' => 'First late failure'],
        'received_at' => $lateAt->subMinute(),
    ]);
    SafeActionLateResult::create([
        'tenant_id' => $workspace['tenant']->id,
        'safe_action_execution_id' => $execution->id,
        'status' => 'failed',
        'payload_summary' => ['summary' => 'Addon finished after cancel'],
        'received_at' => $lateAt,
    ]);

    $expectedResult = 'Cancelled - addon later reported failed (2 late results, latest Nov 22, 9:37 AM)';

    $this->actingAs($rejector)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/index')
            ->where('fixLog.data.0.result', $expectedResult)
            ->where('fixLog.data.0.late_result_count', 2)
            ->where('fixLog.data.0.late_result_status', 'failed')
            ->where('fixLog.data.0.late_result_at', $lateAt->toIso8601String())
            ->where('fixLog.data.0.decision_label', 'Cancelled by'));

    $this->actingAs($rejector)
        ->get(route('reports.fix-log.print', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/fix-log-print')
            ->where('rows.0.result', $expectedResult)
            ->where('rows.0.late_result_count', 2)
            ->where('rows.0.decision_label', 'Cancelled by'));

    $csv = $this->actingAs($rejector)
        ->get(route('reports.fix-log.export', $workspace['tenant']))
        ->assertOk()
        ->streamedContent();

    $lines = array_values(array_filter(preg_split("/\r\n|\n|\r/", trim($csv)) ?: []));
    $headers = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $columns = array_combine($headers, $row);

    expect($columns)->toBeArray()
        ->and($columns['result'])->toBe($expectedResult)
        ->and($columns['late_result_at'])->toBe($lateAt->toIso8601String())
        ->and($columns['late_result_count'])->toBe('2')
        ->and($csv)->toContain('Cancelled by');
});

it('shows a singular late addon result and includes the year when not the current year', function () {
    $this->withoutVite();
    $workspace = createFixLogWorkspace('Singular Late Fix Log WHMCS');
    $workspace['tenant']->forceFill(['timezone' => 'UTC'])->save();
    $rejector = $workspace['owner'];
    $lateAt = CarbonImmutable::parse('2025-03-15T18:05:00Z');

    $execution = SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'pull_and_run_diagnostic_collect',
        'issue_key' => 'diagnostics.stale',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_CANCELLED,
        'risk_level' => 'low',
        'requested_by_user_id' => $rejector->id,
        'rejected_by_user_id' => $rejector->id,
        'rejection_reason' => 'Cancel before addon pickup',
        'requested_at' => now()->subHours(2),
        'rejected_at' => now()->subHour(),
        'late_result_status' => 'success',
        'late_result_at' => $lateAt,
        'result' => [
            'summary' => 'Addon finished after cancel',
            'late' => true,
            'addon_status' => 'success',
        ],
    ]);

    SafeActionLateResult::create([
        'tenant_id' => $workspace['tenant']->id,
        'safe_action_execution_id' => $execution->id,
        'status' => 'success',
        'payload_summary' => ['summary' => 'Only late success'],
        'received_at' => $lateAt,
    ]);

    $expectedResult = 'Cancelled - addon later reported success (1 late result, latest Mar 15, 2025, 6:05 PM)';

    $this->actingAs($rejector)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/index')
            ->where('fixLog.data.0.result', $expectedResult)
            ->where('fixLog.data.0.late_result_count', 1));

    $csv = $this->actingAs($rejector)
        ->get(route('reports.fix-log.export', $workspace['tenant']))
        ->assertOk()
        ->streamedContent();

    $lines = array_values(array_filter(preg_split("/\r\n|\n|\r/", trim($csv)) ?: []));
    $headers = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $columns = array_combine($headers, $row);

    expect($columns)->toBeArray()
        ->and($columns['result'])->toBe($expectedResult)
        ->and($columns['late_result_at'])->toBe($lateAt->toIso8601String())
        ->and($columns['late_result_count'])->toBe('1');
});

it('rejects fix log export for outsiders', function () {
    $a = createFixLogWorkspace();
    $b = createFixLogWorkspace();

    $this->actingAs($a['owner'])
        ->get(route('reports.fix-log.export', $b['tenant']))
        ->assertNotFound();
});

it('renders the printable fix log view', function () {
    $this->withoutVite();
    $workspace = createFixLogWorkspace();

    SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'mode' => SafeActionExecution::MODE_GUIDED,
        'status' => SafeActionExecution::STATUS_VERIFIED,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'minutes_saved' => 15,
        'requested_at' => now()->subHour(),
    ]);

    $this->actingAs($workspace['owner'])
        ->get(route('reports.fix-log.print', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/fix-log-print')
            ->has('rows', 1)
            ->where('rows.0.mode', 'guided'));
});

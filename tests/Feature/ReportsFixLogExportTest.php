<?php

use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;

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

    expect($lines[0])->toBe('date,installation,issue,mode,approved_by,result,minutes_saved');
    expect($csv)->toContain('Alpha WHMCS')
        ->and($csv)->toContain('one_click')
        ->and($csv)->toContain('45')
        ->and($csv)->not->toContain('secret_should_not_appear')
        ->and($csv)->not->toContain('other_tenant_action')
        ->and($csv)->not->toContain('Bravo WHMCS');
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

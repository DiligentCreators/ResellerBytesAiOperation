<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createFixCenterWorkspace(array $entitlements = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Fix Center Workspace',
        'slug' => 'fix-center-'.uniqid(),
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
        'name' => 'Fix Center WHMCS',
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
        'collected_at' => now()->subHour(),
        'received_at' => now()->subHour(),
        'payload' => [
            'system' => ['whmcs_version' => '8.13.7'],
            'whmcs' => [
                'services' => [
                    'suspended_with_paid_invoices' => ['count' => 2, 'service_ids' => [201, 202]],
                ],
            ],
        ],
        'payload_sha256' => hash('sha256', 'fix-center-payload'),
        'analysis_summary' => 'ok',
    ]);

    return compact('owner', 'tenant', 'integration', 'run');
}

function createFixCenterFinding(array $workspace, string $issueKey, array $overrides = []): DiagnosticFinding
{
    return DiagnosticFinding::create(array_merge([
        'diagnostic_run_id' => $workspace['run']->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', $issueKey.'|'.$workspace['run']->id),
        'issue_key' => $issueKey,
        'source' => 'rule',
        'affected_count' => 2,
        'fix_mode' => 'one_click',
        'category' => 'services',
        'severity' => 'warning',
        'title' => $issueKey,
        'summary' => $issueKey,
        'evidence' => [
            'whmcs.services.suspended_with_paid_invoices.service_ids' => [201, 202],
        ],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ], $overrides));
}

it('renders fix center inertia props without internal jargon', function () {
    $workspace = createFixCenterWorkspace();
    $finding = createFixCenterFinding($workspace, 'service.suspended_but_paid');

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->has('issues.data', 1)
            ->where('issues.data.0.id', $finding->id)
            ->where('issues.data.0.primary_action', 'fix_now')
            ->where('issues.data.0.can_one_click', true)
            ->where('issues.data.0.can_bulk', true)
            ->has('issues.data.0.title')
            ->has('issues.data.0.why_it_matters')
            ->has('issues.data.0.installation')
            ->has('issues.data.0.severity')
            ->has('issues.data.0.guided_steps')
            ->has('counts.open')
            ->has('entitlements.fixes_one_click')
            ->has('billing_plans_url')
            ->missing('issues.data.0.fingerprint')
            ->missing('issues.data.0.diagnostic_run_id')
            ->where('filters.tab', 'open'));
});

it('shows starter one-click issues as guided with upgrade link', function () {
    $workspace = createFixCenterWorkspace([
        'fixes.one_click' => false,
        'fixes.bulk' => false,
        'safe_actions.enabled' => false,
    ]);

    createFixCenterFinding($workspace, 'service.suspended_but_paid');

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->where('entitlements.fixes_one_click', false)
            ->where('issues.data.0.primary_action', 'start_guide')
            ->where('issues.data.0.upgrade_to_one_click', true)
            ->where('issues.data.0.can_one_click', false));
});

it('hides ignored findings from open until snooze expires and supports unignore', function () {
    $workspace = createFixCenterWorkspace();
    $finding = createFixCenterFinding($workspace, 'service.suspended_but_paid');

    $this->actingAs($workspace['owner'])
        ->post('/fix-center/'.$finding->id.'/ignore', ['snooze' => '7_days'])
        ->assertRedirect();

    $finding->refresh();
    expect($finding->ignored_until)->not->toBeNull()
        ->and($finding->ignored_until->isFuture())->toBeTrue();

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fix-center/index')
            ->has('issues.data', 0)
            ->where('counts.ignored', 1));

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=ignored')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('issues.data', 1)
            ->where('issues.data.0.id', $finding->id));

    $this->actingAs($workspace['owner'])
        ->post('/fix-center/'.$finding->id.'/unignore')
        ->assertRedirect();

    expect($finding->fresh()->ignored_until)->toBeNull();

    $this->travel(8)->days();

    $finding->forceFill(['ignored_until' => now()->subDay()])->save();

    $this->actingAs($workspace['owner'])
        ->get('/fix-center?tab=open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('issues.data', 1));
});

it('gates growth bulk capability via entitlement props', function () {
    $workspace = createFixCenterWorkspace(['fixes.bulk' => false]);
    createFixCenterFinding($workspace, 'service.suspended_but_paid');

    $this->actingAs($workspace['owner'])
        ->get('/fix-center')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entitlements.fixes_bulk', false)
            ->where('issues.data.0.can_bulk', false)
            ->where('issues.data.0.can_one_click', true));
});

it('returns 404 for another tenant fix center actions', function () {
    $workspace = createFixCenterWorkspace();
    $finding = createFixCenterFinding($workspace, 'cron.not_running', [
        'fix_mode' => 'guided',
        'category' => 'cron',
        'severity' => 'critical',
    ]);
    $outsider = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);

    $this->actingAs($outsider)
        ->get('/fix-center')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('issues.data', 0));

    $this->actingAs($outsider)
        ->post('/fix-center/'.$finding->id.'/ignore', ['snooze' => '30_days'])
        ->assertNotFound();
});

it('keeps legacy findings and safe-actions pages working with fix center links', function () {
    $workspace = createFixCenterWorkspace();
    $finding = createFixCenterFinding($workspace, 'cron.not_running', [
        'fix_mode' => 'guided',
        'category' => 'cron',
        'severity' => 'critical',
        'title' => 'WHMCS system cron is not running',
    ]);

    $this->actingAs($workspace['owner'])
        ->get('/findings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('findings/index'));

    $this->actingAs($workspace['owner'])
        ->get('/findings/'.$finding->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/show')
            ->has('fix_center_url')
            ->has('guided_steps'));

    $this->actingAs($workspace['owner'])
        ->get('/safe-actions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('safe-actions/index'));
});

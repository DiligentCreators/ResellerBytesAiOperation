<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Plan;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantReportSend;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createReportsPageWorkspace(): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Reports Page Workspace',
        'slug' => 'reports-page-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
        'hourly_rate_usd' => 40,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $plan = Plan::factory()->create([
        'code' => 'reports-page-'.uniqid(),
        'entitlements' => [
            'diagnostics.analysis' => true,
            'reports.value' => true,
            'limits' => [
                'members' => 5,
                'integrations' => 2,
                'diagnostics_per_period' => 100,
                'retention_days' => 30,
                'history_days' => 90,
            ],
        ],
    ]);

    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'plan_code' => $plan->code,
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'entitlements' => $plan->entitlements,
        'current_period_starts_at' => now()->subDays(10),
        'current_period_ends_at' => now()->addDays(20),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Reports WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return compact('owner', 'tenant', 'integration', 'plan');
}

it('renders the reports page with empty data safely', function () {
    $this->withoutVite();
    $workspace = createReportsPageWorkspace();

    $this->actingAs($workspace['owner'])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('tenant.id', $workspace['tenant']->id)
            ->has('reports', 0)
            ->where('fixLog.meta.total', 0));
});

it('lists past reports and fix log rows', function () {
    $this->withoutVite();
    $workspace = createReportsPageWorkspace();

    TenantReportSend::factory()->weekly()->create([
        'tenant_id' => $workspace['tenant']->id,
        'period_start' => now()->subDays(7)->toDateString(),
        'period_end' => now()->toDateString(),
        'sent_at' => now()->subDay(),
        'summary' => [
            'health_score' => 88,
            'issues_fixed' => 2,
            'time_saved_hours_display' => '1.5',
            'time_saved_value_display' => '60.00',
        ],
    ]);

    SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_VERIFIED,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'approved_by_user_id' => $workspace['owner']->id,
        'minutes_saved' => 30,
        'requested_at' => now()->subHour(),
        'executed_at' => now()->subMinutes(30),
        'verified_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($workspace['owner'])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->has('reports', 1)
            ->where('fixLog.meta.total', 1)
            ->where('fixLog.data.0.mode', 'one_click')
            ->where('fixLog.data.0.minutes_saved', 30));
});

it('shows cancel value reminder props on the billing page', function () {
    $this->withoutVite();
    $workspace = createReportsPageWorkspace();

    DiagnosticFinding::create([
        'diagnostic_run_id' => DiagnosticRun::create([
            'integration_id' => $workspace['integration']->id,
            'tenant_id' => $workspace['tenant']->id,
            'snapshot_uuid' => (string) Str::uuid(),
            'schema_version' => '1.0',
            'addon_version' => '1.0.0',
            'status' => 'analyzed',
            'collected_at' => now(),
            'received_at' => now(),
            'payload' => [],
            'payload_sha256' => str_repeat('b', 64),
        ])->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'cancel-critical'),
        'category' => 'security',
        'severity' => 'critical',
        'title' => 'Open critical',
        'summary' => 'Still open',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'mode' => SafeActionExecution::MODE_GUIDED,
        'status' => SafeActionExecution::STATUS_VERIFIED,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'minutes_saved' => 60,
        'requested_at' => now()->subDay(),
        'executed_at' => now()->subDay(),
        'verified_at' => now()->subDay(),
    ]);

    $this->actingAs($workspace['owner'])
        ->get(route('billing.settings.show', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/settings')
            ->where('cancelValueReminder.issues_fixed', 0)
            ->where('cancelValueReminder.open_critical', 1)
            ->where('cancelValueReminder.time_saved_hours_display', '1.0'));
});

it('does not expose another tenant report show page', function () {
    $this->withoutVite();
    $a = createReportsPageWorkspace();
    $b = createReportsPageWorkspace();

    $report = TenantReportSend::factory()->weekly()->create([
        'tenant_id' => $b['tenant']->id,
        'sent_at' => now(),
        'summary' => ['tenant_name' => 'Other'],
    ]);

    $this->actingAs($a['owner'])
        ->get(route('reports.show', ['tenant' => $a['tenant'], 'report' => $report]))
        ->assertNotFound();
});

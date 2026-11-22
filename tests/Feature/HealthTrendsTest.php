<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsHealthSnapshot;
use App\Models\WhmcsIntegration;
use App\Services\Health\HealthHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createTrendsWorkspace(int $historyDays = 90, string $planCode = 'starter'): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Trends Workspace',
        'slug' => 'trends-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $plan = Plan::factory()->create([
        'code' => $planCode.'-'.uniqid(),
        'entitlements' => [
            'diagnostics.analysis' => true,
            'limits' => [
                'members' => 5,
                'integrations' => 2,
                'diagnostics_per_period' => 100,
                'retention_days' => 30,
                'history_days' => $historyDays,
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
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Trends WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return compact('owner', 'tenant', 'integration', 'plan');
}

it('clamps trend history to the plan history_days entitlement', function () {
    $starter = createTrendsWorkspace(90, 'starter');
    $growth = createTrendsWorkspace(365, 'growth');

    $history = app(HealthHistoryService::class);

    expect($history->historyDays($starter['tenant']))->toBe(90)
        ->and($history->clampRangeDays($starter['tenant'], 365))->toBe(90)
        ->and($history->historyDays($growth['tenant']))->toBe(365)
        ->and($history->clampRangeDays($growth['tenant'], 365))->toBe(365);
});

it('renders trends inertia props for the requested range', function () {
    Carbon::setTestNow('2026-11-22 12:00:00');
    $workspace = createTrendsWorkspace(365, 'growth');

    WhmcsHealthSnapshot::create([
        'id' => (string) Str::uuid(),
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'captured_at' => now('UTC')->subDays(2),
        'captured_on' => now('UTC')->subDays(2)->toDateString(),
        'score' => 88,
        'critical_count' => 0,
        'warning_count' => 1,
        'info_count' => 0,
        'metrics' => [
            'module_queue_pending' => 0,
            'overdue_unpaid_count' => 3,
            'suspended_with_paid' => 0,
            'sla_breaches' => 0,
            'domains_failing' => 0,
            'heartbeat_state' => 'ok',
            'disk_free_percent' => 55.0,
        ],
    ]);

    $this->actingAs($workspace['owner'])
        ->get(route('reports.trends', ['days' => 30]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/trends')
            ->where('range_days', 30)
            ->where('max_history_days', 365)
            ->has('points', 1)
            ->where('points.0.score', 88)
            ->has('available_ranges'));

    Carbon::setTestNow();
});

it('does not expose another tenant trends page', function () {
    $a = createTrendsWorkspace(90);
    $b = createTrendsWorkspace(90);

    $this->actingAs($a['owner'])
        ->get(route('reports.trends', ['tenant_id' => $b['tenant']->id]))
        ->assertNotFound();
});

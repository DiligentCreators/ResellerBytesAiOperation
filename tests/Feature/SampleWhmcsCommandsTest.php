<?php

use App\Console\Commands\SampleWhmcsIntegrationCommand;
use App\Models\DiagnosticFinding;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Facades\Artisan;

function createSampleCommandWorkspace(User $owner, int $integrationLimit = 5): Tenant
{
    $tenant = Tenant::create([
        'name' => 'Sample Smoke Workspace',
        'slug' => 'sample-smoke-'.uniqid(),
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
        'plan_code' => 'sample-smoke-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => fake()->numberBetween(1000, 9000),
        'entitlements' => ['limits' => ['integrations' => $integrationLimit, 'diagnostics_per_period' => 100]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    return $tenant;
}

it('creates a local demo WHMCS integration without printing secrets', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = createSampleCommandWorkspace($owner);

    $this->artisan('whmcs:sample-integration', ['tenant' => $tenant->id])
        ->expectsOutputToContain('Created local demo WHMCS integration.')
        ->expectsOutputToContain('Integration ID:')
        ->assertSuccessful();

    $output = Artisan::output();
    $integration = WhmcsIntegration::query()->sole();

    expect($output)->not->toMatch('/\b[a-f0-9]{64}\b/')
        ->and($integration->name)->toBe(SampleWhmcsIntegrationCommand::DEMO_INTEGRATION_NAME)
        ->and($integration->tenant_id)->toBe($tenant->id)
        ->and($integration->owner_user_id)->toBe($owner->id)
        ->and($integration->status)->toBe('active')
        ->and($integration->secret)->toMatch('/\A[a-f0-9]{64}\z/');
});

it('reuses an existing local demo WHMCS integration for the tenant', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = createSampleCommandWorkspace($owner);

    $this->artisan('whmcs:sample-integration', ['tenant' => $tenant->id])->assertSuccessful();
    $firstId = WhmcsIntegration::query()->sole()->id;

    $this->artisan('whmcs:sample-integration', ['tenant' => $tenant->id])
        ->expectsOutputToContain('Reused local demo WHMCS integration.')
        ->assertSuccessful();

    expect(WhmcsIntegration::query()->count())->toBe(1)
        ->and(WhmcsIntegration::query()->sole()->id)->toBe($firstId);
});

it('dispatches the positive fixture through the signed API path and creates rule findings', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = createSampleCommandWorkspace($owner);

    $this->artisan('whmcs:sample-integration', ['tenant' => $tenant->id])->assertSuccessful();
    $integration = WhmcsIntegration::query()->sole();

    config(['monitoring.addon_latest_version' => '1.2.0']);

    $this->artisan('whmcs:sample-snapshot', [
        'integration' => $integration->id,
        '--fixture' => 'schema_1_1_positive',
    ])
        ->expectsOutputToContain('HTTP status: 202')
        ->expectsOutputToContain('Findings by source:')
        ->assertSuccessful();

    $keys = DiagnosticFinding::query()
        ->where('source', DiagnosticFinding::SOURCE_RULE)
        ->pluck('issue_key')
        ->all();

    expect($keys)->toContain('module.queue_failed')
        ->and($keys)->toContain('storage.disk_low')
        ->and($keys)->toContain('security.cve_2026_67398')
        ->and($keys)->toContain('tickets.sla_breach');
});

it('opens heartbeat.missed after --stale followed by whmcs:heartbeats:check', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = createSampleCommandWorkspace($owner);

    $this->artisan('whmcs:sample-integration', ['tenant' => $tenant->id])->assertSuccessful();
    $integration = WhmcsIntegration::query()->sole();

    config([
        'monitoring.addon_latest_version' => '1.2.0',
        'monitoring.heartbeat_stale_minutes' => 30,
    ]);

    $this->artisan('whmcs:sample-snapshot', [
        'integration' => $integration->id,
        '--fixture' => 'schema_1_1_positive',
        '--heartbeat' => true,
        '--stale' => true,
    ])->assertSuccessful();

    expect($integration->fresh()->last_heartbeat_at)->not->toBeNull()
        ->and($integration->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_OK);

    $this->artisan('whmcs:heartbeats:check')->assertSuccessful();

    expect($integration->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_STALE)
        ->and(DiagnosticFinding::query()->where('issue_key', 'heartbeat.missed')->active()->count())->toBe(1);
});

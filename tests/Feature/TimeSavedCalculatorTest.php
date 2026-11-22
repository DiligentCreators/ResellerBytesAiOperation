<?php

use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Health\TimeSavedCalculator;
use Illuminate\Support\Carbon;

function createTimeSavedWorkspace(?int $hourlyRate = null): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Time Saved Workspace',
        'slug' => 'time-saved-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
        'hourly_rate_usd' => $hourlyRate,
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
        'name' => 'Time Saved WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    return compact('owner', 'tenant', 'integration');
}

function createVerifiedExecution(array $workspace, array $overrides = []): SafeActionExecution
{
    return SafeActionExecution::create(array_merge([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_VERIFIED,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'minutes_saved' => 60,
        'requested_at' => now()->subHour(),
        'executed_at' => now()->subMinutes(30),
        'verified_at' => now()->subMinutes(10),
    ], $overrides));
}

it('sums minutes_saved only from verified one_click bulk auto and guided executions', function () {
    Carbon::setTestNow('2026-11-15 12:00:00');
    $workspace = createTimeSavedWorkspace(100);

    createVerifiedExecution($workspace, [
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'minutes_saved' => 60,
    ]);
    createVerifiedExecution($workspace, [
        'mode' => SafeActionExecution::MODE_BULK,
        'minutes_saved' => 30,
    ]);
    createVerifiedExecution($workspace, [
        'mode' => SafeActionExecution::MODE_GUIDED,
        'minutes_saved' => 45,
    ]);
    createVerifiedExecution($workspace, [
        'mode' => SafeActionExecution::MODE_AUTO,
        'minutes_saved' => 15,
    ]);
    createVerifiedExecution($workspace, [
        'status' => SafeActionExecution::STATUS_COMPLETED,
        'minutes_saved' => 999,
        'verified_at' => null,
    ]);
    createVerifiedExecution($workspace, [
        'verified_at' => now()->subMonth(),
        'executed_at' => now()->subMonth(),
        'minutes_saved' => 120,
    ]);

    $result = app(TimeSavedCalculator::class)->forTenantMonth($workspace['tenant']);

    expect($result['minutes'])->toBe(150)
        ->and($result['hours'])->toBe(2.5)
        ->and($result['hours_display'])->toBe('2.5')
        ->and($result['hourly_rate_usd'])->toBe(100)
        ->and($result['value_usd'])->toBe(250.0)
        ->and($result['value_display'])->toBe('250.00');

    Carbon::setTestNow();
});

it('falls back to the platform default hourly rate when workspace rate is null', function () {
    $workspace = createTimeSavedWorkspace(null);

    config(['reports.default_hourly_rate_usd' => 25]);

    $calculator = app(TimeSavedCalculator::class);

    expect($calculator->hourlyRateUsd($workspace['tenant']))->toBe(25);

    $workspace['tenant']->forceFill(['hourly_rate_usd' => 80])->save();

    expect($calculator->hourlyRateUsd($workspace['tenant']->fresh()))->toBe(80);
});

it('lets owners update the workspace hourly rate from settings', function () {
    $workspace = createTimeSavedWorkspace(null);

    $this->actingAs($workspace['owner'])
        ->putJson(route('tenants.settings.hourly-rate.update', $workspace['tenant']), [
            'hourly_rate_usd' => 55,
        ])
        ->assertOk()
        ->assertJsonPath('tenant.hourly_rate_usd', 55);

    expect($workspace['tenant']->fresh()->hourly_rate_usd)->toBe(55);

    $this->actingAs($workspace['owner'])
        ->putJson(route('tenants.settings.hourly-rate.update', $workspace['tenant']), [
            'hourly_rate_usd' => null,
        ])
        ->assertOk()
        ->assertJsonPath('tenant.hourly_rate_usd', null);

    expect($workspace['tenant']->fresh()->hourly_rate_usd)->toBeNull();
});

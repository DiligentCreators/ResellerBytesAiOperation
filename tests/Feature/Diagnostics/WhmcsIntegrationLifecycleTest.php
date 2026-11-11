<?php

use App\Models\AuditEvent;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Facades\DB;

test('creates an integration with a one-time encrypted secret and an audit event', function () {
    $this->artisan('whmcs:integration:create', ['name' => 'ResellerBytes Production'])
        ->expectsOutputToContain('WHMCS integration created.')
        ->expectsOutputToContain('Integration secret (shown once):')
        ->assertSuccessful();

    $integration = WhmcsIntegration::query()->sole();
    $storedSecret = DB::table('whmcs_integrations')
        ->where('id', $integration->id)
        ->value('secret');

    expect($integration->name)->toBe('ResellerBytes Production')
        ->and($integration->status)->toBe('active')
        ->and($integration->secret)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($storedSecret)->not->toBe($integration->secret)
        ->and($integration->secret_version)->toBe(1)
        ->and(AuditEvent::where('event_type', 'whmcs_integration.created')->count())->toBe(1)
        ->and(AuditEvent::query()->first()->metadata)->toBe(['secret_version' => 1]);
});

test('rotates an active integration secret and increments its version', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'ResellerBytes Production',
        'status' => 'active',
        'secret' => str_repeat('a', 64),
        'secret_version' => 1,
    ]);

    $oldSecret = $integration->secret;

    $this->artisan('whmcs:integration:rotate', ['integration' => $integration->id])
        ->expectsOutputToContain('WHMCS integration secret rotated.')
        ->expectsOutputToContain('New integration secret (shown once):')
        ->assertSuccessful();

    $integration->refresh();

    expect($integration->secret)->not->toBe($oldSecret)
        ->and($integration->secret)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($integration->secret_version)->toBe(2)
        ->and(AuditEvent::where('event_type', 'whmcs_integration.secret_rotated')->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'whmcs_integration.secret_rotated')->first()->metadata)
        ->toBe(['secret_version' => 2]);
});

test('does not rotate a revoked integration secret', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'Revoked installation',
        'status' => 'revoked',
        'secret' => str_repeat('b', 64),
        'secret_version' => 2,
    ]);

    $oldSecret = $integration->secret;

    $this->artisan('whmcs:integration:rotate', ['integration' => $integration->id])
        ->expectsOutputToContain('No active integration was found')
        ->assertFailed();

    expect($integration->fresh()->secret)->toBe($oldSecret)
        ->and($integration->fresh()->secret_version)->toBe(2)
        ->and(AuditEvent::where('event_type', 'whmcs_integration.secret_rotated')->count())->toBe(0);
});

test('revokes an integration and records the action', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'ResellerBytes Production',
        'status' => 'active',
        'secret' => str_repeat('c', 64),
        'secret_version' => 1,
    ]);

    $oldSecret = $integration->secret;

    $this->artisan('whmcs:integration:revoke', ['integration' => $integration->id])
        ->expectsOutputToContain('WHMCS integration is revoked.')
        ->assertSuccessful();

    expect($integration->fresh()->status)->toBe('revoked')
        ->and($integration->fresh()->secret)->not->toBe($oldSecret)
        ->and($integration->fresh()->secret_version)->toBe(2)
        ->and(AuditEvent::where('event_type', 'whmcs_integration.revoked')->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'whmcs_integration.revoked')->first()->metadata)
        ->toBe(['secret_version' => 2]);
});

test('rejects an invalid integration name without creating a record', function () {
    $this->artisan('whmcs:integration:create', ['name' => str_repeat('x', 121)])
        ->expectsOutputToContain('The integration name must contain between 1 and 120 characters.')
        ->assertFailed();

    expect(WhmcsIntegration::count())->toBe(0);
});

<?php

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

test('creates an integration with a one-time encrypted secret and an audit event', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test', 'email_verified_at' => now()]);

    $this->artisan('whmcs:integration:create', ['name' => 'ResellerBytes Production', '--owner' => $owner->email])
        ->expectsOutputToContain('WHMCS integration created.')
        ->expectsOutputToContain('Integration secret (shown once):')
        ->assertSuccessful();

    $integration = WhmcsIntegration::query()->sole();
    $storedSecret = DB::table('whmcs_integrations')
        ->where('id', $integration->id)
        ->value('secret');

    expect($integration->owner_user_id)->toBe($owner->id)
        ->and($integration->name)->toBe('ResellerBytes Production')
        ->and($integration->status)->toBe('active')
        ->and($integration->secret)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($storedSecret)->not->toBe($integration->secret)
        ->and($integration->secret_version)->toBe(1)
        ->and(AuditEvent::where('event_type', 'whmcs_integration.created')->count())->toBe(1)
        ->and(AuditEvent::query()->first()->metadata)->toBe(['owner_user_id' => (string) $owner->id, 'secret_version' => 1]);
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

test('prevents deleting integrations so their audit and diagnostic history remains addressable', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'Retained installation',
        'status' => 'revoked',
        'secret' => str_repeat('d', 64),
        'secret_version' => 2,
    ]);

    expect(fn () => $integration->delete())
        ->toThrow(LogicException::class, 'revoke the integration instead of deleting it');

    expect(WhmcsIntegration::query()->whereKey($integration->id)->exists())->toBeTrue();
});

test('rejects an invalid integration name without creating a record', function () {
    $this->artisan('whmcs:integration:create', ['name' => str_repeat('x', 121)])
        ->expectsOutputToContain('The integration name must contain between 1 and 120 characters.')
        ->assertFailed();

    expect(WhmcsIntegration::count())->toBe(0);
});

test('prevents query-builder deletion so integration history cannot be bypassed', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'Retained installation',
        'status' => 'revoked',
        'secret' => str_repeat('e', 64),
        'secret_version' => 3,
    ]);

    expect(fn () => DB::table('whmcs_integrations')->where('id', $integration->id)->delete())
        ->toThrow(QueryException::class, 'whmcs_integrations are retained for audit history');

    expect(DB::table('whmcs_integrations')->where('id', $integration->id)->exists())->toBeTrue();
});

test('assigns an unowned legacy integration to a verified MFA owner with immutable audit evidence', function () {
    $owner = User::factory()->withTwoFactor()->create(['email' => 'legacy-owner@example.test', 'email_verified_at' => now()]);
    $integration = WhmcsIntegration::create([
        'name' => 'Legacy unassigned integration',
        'status' => 'active',
        'secret' => str_repeat('f', 64),
        'secret_version' => 1,
    ]);

    $this->artisan('whmcs:integration:assign-owner', [
        'integration' => $integration->id,
        'email' => $owner->email,
    ])->assertSuccessful();

    expect($integration->fresh()->owner_user_id)->toBe($owner->id)
        ->and(AuditEvent::query()->where('event_type', 'whmcs_integration.owner_assigned')->sole()->metadata)
        ->toBe(['owner_user_id' => (string) $owner->id]);
});

test('does not reassign an integration that already has an owner', function () {
    $firstOwner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $secondOwner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $firstOwner->id,
        'name' => 'Already assigned integration',
        'status' => 'active',
        'secret' => str_repeat('9', 64),
        'secret_version' => 1,
    ]);

    $this->artisan('whmcs:integration:assign-owner', [
        'integration' => $integration->id,
        'email' => $secondOwner->email,
    ])->assertFailed();

    expect($integration->fresh()->owner_user_id)->toBe($firstOwner->id)
        ->and(AuditEvent::where('event_type', 'whmcs_integration.owner_assigned')->count())->toBe(0);
});


test('provisions tenant integrations only for authorized members within the active plan limit', function () {
    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'tenant-owner@example.test',
        'email_verified_at' => now(),
    ]);
    $tenant = \App\Models\Tenant::create([
        'name' => 'Provisioning Workspace',
        'slug' => 'provisioning-workspace',
        'status' => \App\Models\Tenant::STATUS_ACTIVE,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => \App\Models\TenantMembership::ROLE_OWNER,
        'status' => \App\Models\TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    \App\Models\TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'tenant-provisioning-test',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['limits' => ['integrations' => 1]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $this->artisan('whmcs:integration:create', [
        'name' => 'Tenant WHMCS',
        '--owner' => $owner->email,
        '--tenant' => $tenant->id,
    ])->assertSuccessful();

    $integration = WhmcsIntegration::query()->sole();
    expect($integration->tenant_id)->toBe($tenant->id)
        ->and(AuditEvent::query()->where('event_type', 'whmcs_integration.created')->sole()->tenant_id)->toBe($tenant->id);

    $this->artisan('whmcs:integration:create', [
        'name' => 'Over Limit WHMCS',
        '--owner' => $owner->email,
        '--tenant' => $tenant->id,
    ])->assertFailed();

    expect(WhmcsIntegration::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'whmcs_integration.created')->count())->toBe(1);
});

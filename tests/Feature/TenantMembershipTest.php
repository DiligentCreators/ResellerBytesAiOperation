<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\TenantAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('creates an isolated tenant and explicit role-based memberships without implicit access', function () {
    $creator = User::factory()->withTwoFactor()->create();
    $member = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();

    $tenant = Tenant::create([
        'name' => 'Approved Workspace',
        'slug' => 'approved-workspace',
        'status' => Tenant::STATUS_PROVISIONING,
        'created_by_user_id' => $creator->id,
    ]);

    $membership = $tenant->memberships()->create([
        'user_id' => $member->id,
        'role' => TenantMembership::ROLE_OPERATOR,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    expect($membership->isActive())->toBeTrue()
        ->and($membership->hasAnyRole([TenantMembership::ROLE_OPERATOR, TenantMembership::ROLE_ADMIN]))->toBeTrue()
        ->and($membership->hasAnyRole([TenantMembership::ROLE_BILLING]))->toBeFalse()
        ->and($tenant->memberships()->where('user_id', $outsider->id)->exists())->toBeFalse()
        ->and($member->tenants()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and($outsider->tenants()->whereKey($tenant->id)->exists())->toBeFalse();
});

it('treats suspended and revoked memberships as non-authorizing', function () {
    $user = User::factory()->withTwoFactor()->create();
    $tenant = Tenant::create([
        'name' => 'Membership State Workspace',
        'slug' => 'membership-state-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);

    $membership = $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_SUSPENDED,
    ]);

    expect($membership->isActive())->toBeFalse()
        ->and($membership->hasAnyRole([TenantMembership::ROLE_OWNER]))->toBeFalse();

    $membership->forceFill(['status' => TenantMembership::STATUS_REVOKED])->save();

    expect($membership->fresh()->isActive())->toBeFalse();
});

it('supports nullable tenant assignment for legacy integrations without guessing a tenant', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'Quarantined legacy integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    expect($integration->tenant_id)->toBeNull()
        ->and($integration->tenant()->exists())->toBeFalse();
});


it('requires an active tenant and active membership before granting tenant access', function () {
    $user = User::factory()->withTwoFactor()->create();
    $tenant = Tenant::create([
        'name' => 'Access Policy Workspace',
        'slug' => 'access-policy-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $access = app(TenantAccess::class);

    expect(fn () => $access->requireMembership($user, $tenant->id))
        ->toThrow(ModelNotFoundException::class);

    $membership = $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
    ]);

    expect($access->requireMembership($user, $tenant->id)->id)->toBe($membership->id);
    expect(fn () => $access->requireMembership($user, $tenant->id, [TenantMembership::ROLE_ADMIN]))
        ->toThrow(AuthorizationException::class);

    $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
    expect(fn () => $access->requireMembership($user, $tenant->id))
        ->toThrow(ModelNotFoundException::class);
});

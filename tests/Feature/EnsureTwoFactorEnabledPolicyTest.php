<?php

use App\Models\CentralStaffAccess;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\Tenancy\TenantProvisioningService;

test('self-serve customers without two-factor can access the dashboard', function () {
    $user = User::factory()->create();
    app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Customer Workspace',
        'country_code' => 'US',
    ]);

    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('when platform 2FA requirement is off, operators without two-factor can reach dashboard and platform settings', function () {
    config([
        'operations.owner_email' => 'staff-owner@example.test',
        'security.two_factor_required' => false,
    ]);

    $user = User::factory()->create([
        'email' => 'staff-owner@example.test',
    ]);

    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('platform.settings.show'))
        ->assertOk();
});

test('when platform 2FA requirement is off, tenant require_two_factor does not redirect members', function () {
    config(['security.two_factor_required' => false]);

    $owner = User::factory()->withTwoFactor()->create();
    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($owner, [
        'name' => 'Strict Workspace Off',
        'country_code' => 'DE',
    ]);
    $tenant->forceFill(['require_two_factor' => true, 'onboarding_completed_at' => now()])->save();

    $member = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $member->id,
        'role' => TenantMembership::ROLE_OPERATOR,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk();
});

test('central platform staff without two-factor are redirected to security settings', function () {
    config([
        'operations.owner_email' => 'staff-owner@example.test',
        'security.two_factor_required' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'staff-owner@example.test',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('central staff access rows without two-factor are redirected', function () {
    config(['security.two_factor_required' => true]);

    $granter = User::factory()->withTwoFactor()->create();
    $user = User::factory()->create();
    CentralStaffAccess::query()->create([
        'user_id' => $user->id,
        'permissions' => ['support'],
        'granted_by_user_id' => $granter->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('tenant require_two_factor blocks members without confirmed two-factor', function () {
    config(['security.two_factor_required' => true]);

    $owner = User::factory()->withTwoFactor()->create();
    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($owner, [
        'name' => 'Strict Workspace',
        'country_code' => 'DE',
    ]);
    $tenant->forceFill(['require_two_factor' => true, 'onboarding_completed_at' => now()])->save();

    $member = User::factory()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $member->id,
        'role' => TenantMembership::ROLE_OPERATOR,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('central staff with settings permission can toggle platform two-factor requirement via UI', function () {
    config([
        'operations.owner_email' => 'owner@example.test',
        'security.two_factor_required' => false,
    ]);

    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['settings'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($staff)
        ->from(route('platform.settings.show', ['group' => 'security']))
        ->put(route('platform.settings.update', 'security'), [
            'settings' => [
                'security.two_factor_required' => true,
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('platform.settings.show', ['group' => 'security']));

    app()->forgetInstance(PlatformSettings::class);
    $settings = app(PlatformSettings::class);

    expect($settings->get('security.two_factor_required'))->toBeTrue()
        ->and($settings->source('security.two_factor_required'))->toBe('database')
        ->and(config('security.two_factor_required'))->toBeTrue();

    $operatorWithoutTwoFactor = User::factory()->create([
        'email' => 'operator-no-2fa-'.uniqid('', true).'@example.test',
    ]);
    CentralStaffAccess::query()->create([
        'user_id' => $operatorWithoutTwoFactor->id,
        'permissions' => ['support'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($operatorWithoutTwoFactor)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

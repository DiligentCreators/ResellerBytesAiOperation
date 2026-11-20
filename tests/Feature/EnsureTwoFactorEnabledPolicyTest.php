<?php

use App\Models\CentralStaffAccess;
use App\Models\TenantMembership;
use App\Models\User;
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

test('central platform staff without two-factor are redirected to security settings', function () {
    config(['operations.owner_email' => 'staff-owner@example.test']);

    $user = User::factory()->create([
        'email' => 'staff-owner@example.test',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('central staff access rows without two-factor are redirected', function () {
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

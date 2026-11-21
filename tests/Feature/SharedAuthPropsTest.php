<?php

use App\Models\CentralStaffAccess;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares platform operator flags and empty tenants for the configured owner', function () {
    config(['operations.owner_email' => 'owner@example.test']);

    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'owner@example.test',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.is_platform_admin', true)
            ->where('auth.is_platform_operator', true)
            ->where('auth.can_manage_integrations', false)
            ->where('auth.tenants', [])
            ->where('auth.central_permissions', ['tenants', 'billing', 'billing.settings.manage', 'support', 'settings', 'audit'])
            ->where('security.twoFactorRequiredByPlatform', false));
});

it('shares tenant memberships and integration manage capability for a workspace owner', function () {
    config(['operations.owner_email' => 'someone-else@example.test']);

    $customer = User::factory()->withTwoFactor()->create([
        'email' => 'customer@example.test',
        'email_verified_at' => now(),
    ]);

    $tenant = Tenant::create([
        'name' => 'Customer Workspace',
        'slug' => 'customer-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $tenant->memberships()->create([
        'user_id' => $customer->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($customer)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.is_platform_admin', false)
            ->where('auth.is_platform_operator', false)
            ->where('auth.can_manage_integrations', true)
            ->where('auth.tenants.0.id', $tenant->id)
            ->where('auth.tenants.0.role', 'owner')
            ->where('auth.central_permissions', []));
});

it('treats central staff as platform operators without integration manage by default', function () {
    config(['operations.owner_email' => 'owner@example.test']);

    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'owner@example.test',
        'email_verified_at' => now(),
    ]);

    $staff = User::factory()->withTwoFactor()->create([
        'email' => 'staff@example.test',
        'email_verified_at' => now(),
    ]);

    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['support'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($staff)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.is_platform_admin', false)
            ->where('auth.is_platform_operator', true)
            ->where('auth.can_manage_integrations', false)
            ->where('auth.central_permissions', ['support']));
});

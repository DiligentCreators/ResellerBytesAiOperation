<?php

use App\Models\CentralStaffAccess;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the five customer navigation items for workspace owners', function () {
    config(['operations.owner_email' => 'someone-else@example.test']);

    $customer = User::factory()->withTwoFactor()->create([
        'email' => 'customer-nav@example.test',
        'email_verified_at' => now(),
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Nav Workspace',
        'slug' => 'nav-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $tenant->memberships()->create([
        'user_id' => $customer->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('navigation.customer', 5)
            ->where('navigation.customer.0.title', 'Home')
            ->where('navigation.customer.0.href', '/dashboard')
            ->where('navigation.customer.1.title', 'Fix Center')
            ->where('navigation.customer.2.title', 'Reports')
            ->where('navigation.customer.3.title', 'Installations')
            ->where('navigation.customer.3.href', '/integrations')
            ->where('navigation.customer.3.children.3.title', 'History')
            ->where('navigation.customer.3.children.3.href', '/diagnostic-runs')
            ->where('navigation.customer.4.title', 'Settings')
            ->where('navigation.customer.4.children.3.title', 'Auto-fix')
            ->where('navigation.platform', []));
});

it('shares platform navigation for central staff without customer workspace', function () {
    config(['operations.owner_email' => 'owner-nav@example.test']);

    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'owner-nav@example.test',
        'email_verified_at' => now(),
    ]);

    $staff = User::factory()->withTwoFactor()->create([
        'email' => 'staff-nav@example.test',
        'email_verified_at' => now(),
    ]);

    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['billing', 'settings'],
        'granted_by_user_id' => $owner->id,
    ]);

    $this->actingAs($staff)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.customer', [])
            ->where('navigation.platform.0.title', 'Overview / MRR')
            ->where('navigation.platform.1.title', 'Plan catalog')
            ->where('navigation.platform.2.title', 'Gateway')
            ->where('navigation.platform.3.title', 'Platform settings')
            ->where('navigation.platform.4.title', 'Mail')
            ->where('navigation.platform.5.title', 'AI and signing'));
});

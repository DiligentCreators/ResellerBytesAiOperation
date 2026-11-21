<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;

it('returns 404 for non-members inviting with an unknown email before validation oracle', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $outsider = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Invite Auth Tenant',
        'slug' => 'invite-auth-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($outsider)
        ->postJson(route('tenants.memberships.invite', $tenant), [
            'email' => 'definitely-missing-'.uniqid().'@example.test',
            'role' => TenantMembership::ROLE_VIEWER,
        ])
        ->assertNotFound();
});

it('returns 404 for non-members inviting with a verified email', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $outsider = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $invitee = User::factory()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Invite Auth Verified',
        'slug' => 'invite-auth-v-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($outsider)
        ->postJson(route('tenants.memberships.invite', $tenant), [
            'email' => $invitee->email,
            'role' => TenantMembership::ROLE_VIEWER,
        ])
        ->assertNotFound();
});

it('returns 404 for non-members updating branding with invalid payload', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $outsider = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Brand Auth Tenant',
        'slug' => 'brand-auth-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($outsider)
        ->putJson(route('tenants.settings.branding.update', $tenant), [
            'primary_color' => 'not-a-color',
        ])
        ->assertNotFound();
});

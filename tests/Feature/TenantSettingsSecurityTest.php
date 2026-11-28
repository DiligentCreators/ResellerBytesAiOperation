<?php

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function securitySettingsWorkspace(string $role = TenantMembership::ROLE_OWNER): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Security Settings Workspace',
        'slug' => 'security-settings-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'require_two_factor' => false,
        'created_by_user_id' => $user->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => $role,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    return compact('user', 'tenant');
}

it('lets owners and admins update workspace require_two_factor and audits the change', function () {
    $workspace = securitySettingsWorkspace(TenantMembership::ROLE_OWNER);

    $this->actingAs($workspace['user'])
        ->get(route('tenants.settings.show', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/settings')
            ->where('security.require_two_factor', false)
            ->has('security.platform_two_factor_required'));

    $this->actingAs($workspace['user'])
        ->putJson(route('tenants.settings.security.update', $workspace['tenant']), [
            'require_two_factor' => true,
        ])
        ->assertOk()
        ->assertJsonPath('tenant.require_two_factor', true);

    expect($workspace['tenant']->fresh()->require_two_factor)->toBeTrue()
        ->and(AuditEvent::query()
            ->where('tenant_id', $workspace['tenant']->id)
            ->where('event_type', 'tenant.security.updated')
            ->exists())->toBeTrue();

    $adminWorkspace = securitySettingsWorkspace(TenantMembership::ROLE_ADMIN);

    $this->actingAs($adminWorkspace['user'])
        ->putJson(route('tenants.settings.security.update', $adminWorkspace['tenant']), [
            'require_two_factor' => true,
        ])
        ->assertOk()
        ->assertJsonPath('tenant.require_two_factor', true);

    expect($adminWorkspace['tenant']->fresh()->require_two_factor)->toBeTrue();
});

it('forbids viewers and other tenants from updating workspace security settings', function () {
    $workspace = securitySettingsWorkspace(TenantMembership::ROLE_VIEWER);

    $this->actingAs($workspace['user'])
        ->putJson(route('tenants.settings.security.update', $workspace['tenant']), [
            'require_two_factor' => true,
        ])
        ->assertForbidden();

    expect($workspace['tenant']->fresh()->require_two_factor)->toBeFalse();

    $outsider = securitySettingsWorkspace(TenantMembership::ROLE_OWNER);

    $this->actingAs($outsider['user'])
        ->putJson(route('tenants.settings.security.update', $workspace['tenant']), [
            'require_two_factor' => true,
        ])
        ->assertNotFound();
});

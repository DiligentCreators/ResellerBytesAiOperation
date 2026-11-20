<?php

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Inertia\Testing\AssertableInertia as Assert;

function provisionTenantWorkspace(User $user, string $role = TenantMembership::ROLE_OWNER, int $integrationLimit = 5): Tenant
{
    $tenant = Tenant::create([
        'name' => 'HTTP Provision Workspace',
        'slug' => 'http-provision-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);

    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => $role,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'http-provision-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['limits' => ['integrations' => $integrationLimit]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    return $tenant;
}

it('lets a tenant owner create an integration and flashes the secret once', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = provisionTenantWorkspace($owner);

    $response = $this->actingAs($owner)->post('/integrations', [
        'name' => 'Customer WHMCS',
        'tenant_id' => $tenant->id,
    ]);

    $response->assertRedirect(route('integrations.index'));
    $response->assertSessionHas('integration_credentials');

    $integration = WhmcsIntegration::query()->sole();
    $flash = session('integration_credentials');

    expect($integration->tenant_id)->toBe($tenant->id)
        ->and($integration->owner_user_id)->toBe($owner->id)
        ->and($integration->name)->toBe('Customer WHMCS')
        ->and($flash['integration_id'])->toBe($integration->id)
        ->and($flash['secret'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($flash['action'])->toBe('created')
        ->and(AuditEvent::query()->where('event_type', 'whmcs_integration.created')->sole()->actor_type)->toBe('user');

    $this->actingAs($owner)
        ->get('/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/index')
            ->where('flash.integration_credentials.integration_id', $integration->id)
            ->where('flash.integration_credentials.secret', $flash['secret'])
            ->missing('integrations.data.0.secret'));

    $this->actingAs($owner)
        ->get('/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.integration_credentials', null));
});

it('denies integration create for a viewer', function () {
    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = provisionTenantWorkspace($viewer, TenantMembership::ROLE_VIEWER);

    $this->actingAs($viewer)
        ->post('/integrations', [
            'name' => 'Blocked WHMCS',
            'tenant_id' => $tenant->id,
        ])
        ->assertSessionHasErrors('tenant_id');

    expect(WhmcsIntegration::query()->count())->toBe(0);
});

it('rejects create when the tenant integration quota is exhausted', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = provisionTenantWorkspace($owner, TenantMembership::ROLE_OWNER, 1);

    WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Existing WHMCS',
        'status' => 'active',
        'secret' => str_repeat('a', 64),
        'secret_version' => 1,
    ]);

    $this->actingAs($owner)
        ->post('/integrations', [
            'name' => 'Over Quota WHMCS',
            'tenant_id' => $tenant->id,
        ])
        ->assertSessionHasErrors('name');

    expect(WhmcsIntegration::query()->count())->toBe(1);
});

it('lets a tenant owner rotate a secret once through the UI', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = provisionTenantWorkspace($owner);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Rotate Me',
        'status' => 'active',
        'secret' => str_repeat('b', 64),
        'secret_version' => 1,
    ]);

    $oldSecret = $integration->secret;

    $response = $this->actingAs($owner)->post(route('integrations.rotate', $integration));

    $response->assertRedirect(route('integrations.index'));
    $response->assertSessionHas('integration_credentials');

    $integration->refresh();
    $flash = session('integration_credentials');

    expect($integration->secret)->not->toBe($oldSecret)
        ->and($integration->secret_version)->toBe(2)
        ->and($flash['action'])->toBe('rotated')
        ->and($flash['secret'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and(AuditEvent::query()->where('event_type', 'whmcs_integration.secret_rotated')->sole()->actor_type)->toBe('user');
});

it('forbids rotate for a viewer on a tenant integration', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = provisionTenantWorkspace($owner);

    $tenant->memberships()->create([
        'user_id' => $viewer->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Protected',
        'status' => 'active',
        'secret' => str_repeat('c', 64),
        'secret_version' => 1,
    ]);

    $this->actingAs($viewer)
        ->post(route('integrations.rotate', $integration))
        ->assertForbidden();

    expect($integration->fresh()->secret_version)->toBe(1);
});

it('hides the artisan create surface from customers on the integrations page', function () {
    config(['operations.owner_email' => 'platform-owner@example.test']);

    $customer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    provisionTenantWorkspace($customer);

    $this->actingAs($customer)
        ->get('/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/index')
            ->where('auth.is_platform_operator', false)
            ->where('auth.can_manage_integrations', true));
});

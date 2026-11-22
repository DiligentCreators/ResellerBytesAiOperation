<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

function heartbeatOwnerFixture(): array
{
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Heartbeat Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    return [$user, $tenant];
}

it('requires authentication for the heartbeat status endpoint', function () {
    $this->getJson(route('onboarding.heartbeat-status'))
        ->assertUnauthorized();
});

it('returns heartbeat status for the owner tenant only', function () {
    [$owner, $tenant] = heartbeatOwnerFixture();

    WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $owner->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => now('UTC'),
    ]);

    $outsider = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);
    $otherTenant = Tenant::query()->create([
        'name' => 'Other',
        'slug' => 'other-heartbeat',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $otherTenant->memberships()->create([
        'user_id' => $outsider->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($outsider)
        ->getJson(route('onboarding.heartbeat-status'))
        ->assertOk()
        ->assertJson([
            'connected' => false,
        ]);

    $this->actingAs($owner)
        ->getJson(route('onboarding.heartbeat-status'))
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('whmcs_step_complete', true);
});

it('throttles the heartbeat status endpoint', function () {
    [$owner] = heartbeatOwnerFixture();

    RateLimiter::clear('onboarding-heartbeat-status|'.$owner->id);

    $this->actingAs($owner);

    for ($i = 0; $i < 30; $i++) {
        $this->getJson(route('onboarding.heartbeat-status'))->assertOk();
    }

    $this->getJson(route('onboarding.heartbeat-status'))->assertStatus(429);
});

it('marks the whmcs onboarding step complete once a heartbeat arrives', function () {
    [$owner, $tenant] = heartbeatOwnerFixture();

    $this->actingAs($owner)
        ->post(route('onboarding.plan'))
        ->assertRedirect(route('onboarding.show'));

    WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $owner->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->where('steps.whmcs', true)
            ->where('currentStep', 'first_scan'));
});

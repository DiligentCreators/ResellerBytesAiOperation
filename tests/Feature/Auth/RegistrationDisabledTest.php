<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\Tenancy\TenantMembershipLifecycle;
use App\Support\RegistrationPolicy;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

function registrationGatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Closed Sign Up User',
        'email' => 'closed-signup@example.test',
        'password' => 'Secure-Passw0rd!',
        'password_confirmation' => 'Secure-Passw0rd!',
        'workspace_name' => 'Closed Sign Up Workspace',
        'country' => 'US',
        'terms' => '1',
        'website' => '',
    ], $overrides);
}

function closePublicRegistration(): void
{
    config(['security.registration_open' => false]);
    expect(app(RegistrationPolicy::class)->isOpen())->toBeFalse();
}

function openPublicRegistration(): void
{
    config(['security.registration_open' => true]);
    expect(app(RegistrationPolicy::class)->isOpen())->toBeTrue();
}

test('when registration is off, GET and POST register are blocked with a friendly login redirect', function () {
    closePublicRegistration();

    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', RegistrationPolicy::CLOSED_MESSAGE);

    $this->from(route('login'))
        ->post(route('register.store'), registrationGatePayload())
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', RegistrationPolicy::CLOSED_MESSAGE);

    $this->assertGuest();
    expect(User::query()->where('email', 'closed-signup@example.test')->exists())->toBeFalse();
});

test('when registration is on, GET and POST register remain available', function () {
    openPublicRegistration();

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/register'));

    $this->post(route('register.store'), registrationGatePayload([
        'email' => 'open-signup@example.test',
    ]))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::query()->where('email', 'open-signup@example.test')->exists())->toBeTrue();
});

test('when registration is off, invitation acceptance still works', function () {
    closePublicRegistration();
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);

    $tenant = Tenant::create([
        'name' => 'Invite When Closed Workspace',
        'slug' => 'invite-when-closed-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $invitee = User::factory()->create(['email_verified_at' => now()]);

    $tenant->memberships()->create([
        'user_id' => $admin->id,
        'role' => TenantMembership::ROLE_ADMIN,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'invite-closed-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['limits' => ['members' => 10]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $lifecycle = app(TenantMembershipLifecycle::class);
    $invitation = $lifecycle->invite(
        $admin,
        $tenant->id,
        $invitee->email,
        TenantMembership::ROLE_VIEWER,
    );

    expect($invitation->status)->toBe(TenantMembership::STATUS_INVITED);

    $this->actingAs($invitee)
        ->post(route('tenants.memberships.accept', ['tenantId' => $tenant->id]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Workspace invitation accepted.');

    expect($invitation->fresh()->status)->toBe(TenantMembership::STATUS_ACTIVE)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('when registration is off, platform admin account creation still works', function () {
    closePublicRegistration();

    $password = 'Secure-Operations-Password-123!';

    $this->artisan('ops:user:create', [
        'name' => 'Ops When Closed',
        'email' => 'ops-when-closed@example.test',
    ])
        ->expectsQuestion('Password (minimum 14 characters)', $password)
        ->expectsQuestion('Confirm password', $password)
        ->assertSuccessful();

    $user = User::query()->where('email', 'ops-when-closed@example.test')->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check($password, $user->password))->toBeTrue();
});

test('when registration is off, login still works', function () {
    closePublicRegistration();

    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('login hides the create-account link when registration is off', function () {
    closePublicRegistration();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->where('canRegister', false)
            ->where('status', null));
});

test('database override of registration_open is enforced on register', function () {
    openPublicRegistration();

    app(PlatformSettings::class)->set('security.registration_open', false);

    expect(app(RegistrationPolicy::class)->isOpen())->toBeFalse()
        ->and(config('security.registration_open'))->toBeFalse();

    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', RegistrationPolicy::CLOSED_MESSAGE);
});

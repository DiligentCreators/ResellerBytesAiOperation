<?php

use App\Models\CentralStaffAccess;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

function validRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test User',
        'email' => 'new-customer@example.test',
        'password' => 'Secure-Passw0rd!',
        'password_confirmation' => 'Secure-Passw0rd!',
        'workspace_name' => 'Acme Hosting',
        'country' => 'US',
        'terms' => '1',
        'website' => '',
    ], $overrides);
}

test('registration screen can be rendered', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/register')
            ->has('countries')
            ->has('passwordRules'));
});

test('new users can register with a workspace and owner membership', function () {
    Event::fake([Registered::class]);
    Notification::fake();

    $response = $this->post(route('register.store'), validRegistrationPayload());

    $user = User::query()->where('email', 'new-customer@example.test')->sole();
    $tenant = Tenant::query()->where('created_by_user_id', $user->id)->sole();

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect($user->email_verified_at)->toBeNull()
        ->and($tenant->name)->toBe('Acme Hosting')
        ->and($tenant->country_code)->toBe('US')
        ->and($tenant->onboarding_completed_at)->toBeNull()
        ->and(TenantMembership::query()->where([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantMembership::ROLE_OWNER,
            'status' => TenantMembership::STATUS_ACTIVE,
        ])->exists())->toBeTrue()
        ->and(CentralStaffAccess::query()->where('user_id', $user->id)->exists())->toBeFalse();

    Event::assertDispatched(Registered::class);
});

test('registration requires email verification before dashboard access', function () {
    $this->post(route('register.store'), validRegistrationPayload([
        'email' => 'unverified@example.test',
    ]));

    $this->assertAuthenticated();

    $this->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('registration validation failures are returned', function () {
    $this->post(route('register.store'), validRegistrationPayload([
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
        'workspace_name' => 'A',
        'country' => 'XX',
        'terms' => null,
    ]))->assertSessionHasErrors([
        'email',
        'password',
        'workspace_name',
        'country',
        'terms',
    ]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('registration rejects honeypot submissions', function () {
    $this->post(route('register.store'), validRegistrationPayload([
        'website' => 'https://spam.example',
    ]))->assertSessionHasErrors('website');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('registration is rate limited', function () {
    $invalid = [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'x',
        'password_confirmation' => 'y',
        'workspace_name' => 'A',
        'country' => 'XX',
        'terms' => null,
        'website' => '',
    ];

    for ($i = 0; $i < 5; $i++) {
        $this->from(route('register'))
            ->post(route('register.store'), $invalid)
            ->assertRedirect();
    }

    $this->from(route('register'))
        ->post(route('register.store'), $invalid)
        ->assertTooManyRequests();

    $this->assertGuest();
});

test('registered users do not receive central staff permissions', function () {
    $this->post(route('register.store'), validRegistrationPayload([
        'email' => 'no-staff@example.test',
    ]))->assertRedirect();

    $user = User::query()->where('email', 'no-staff@example.test')->sole();

    expect(CentralStaffAccess::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(app(CentralStaffAuthorization::class)->permissions($user))->toBe([]);
});

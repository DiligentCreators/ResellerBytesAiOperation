<?php

use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

function provisionVerifiedOwner(): array
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Onboarding Co',
        'country_code' => 'GB',
        'timezone' => 'UTC',
    ]);

    return [$user, $tenant];
}

test('verified owners can open the onboarding wizard', function () {
    [$user] = provisionVerifiedOwner();

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->where('currentStep', 'plan')
            ->where('steps.workspace', true)
            ->where('steps.plan', false));
});

test('owners can complete the three onboarding steps', function () {
    [$user, $tenant] = provisionVerifiedOwner();

    $this->actingAs($user)
        ->put(route('onboarding.workspace'), [
            'name' => 'Onboarding Co Ltd',
            'timezone' => 'Europe/London',
            'require_two_factor' => false,
        ])
        ->assertRedirect(route('onboarding.show'));

    expect($tenant->fresh()->timezone)->toBe('Europe/London')
        ->and($tenant->fresh()->name)->toBe('Onboarding Co Ltd');

    $this->actingAs($user)
        ->post(route('onboarding.plan'))
        ->assertRedirect(route('onboarding.show'));

    $this->actingAs($user)
        ->post(route('onboarding.whmcs'))
        ->assertRedirect(route('dashboard'));

    expect($tenant->fresh()->onboarding_completed_at)->not->toBeNull();
});

test('dashboard shows an onboarding checklist until complete', function () {
    [$user] = provisionVerifiedOwner();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('onboarding.needed', true)
            ->where('onboarding.steps.workspace', true)
            ->where('onboarding.steps.plan', false)
            ->where('onboarding.steps.whmcs', false));
});

test('billing plans placeholder route is available', function () {
    [$user] = provisionVerifiedOwner();

    $this->actingAs($user)
        ->get(route('billing.plans'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('billing/plans'));
});

test('email verification redirects owners into onboarding', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Verify Flow Co',
        'country_code' => 'CA',
    ]);

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect(route('onboarding.show', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

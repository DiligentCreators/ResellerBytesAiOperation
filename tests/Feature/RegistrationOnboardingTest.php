<?php

use App\Models\DiagnosticRun;
use App\Models\User;
use App\Models\WhmcsIntegration;
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

test('owners can complete the onboarding steps through first scan', function () {
    [$user, $tenant] = provisionVerifiedOwner();

    $this->actingAs($user)
        ->put(route('onboarding.workspace'), [
            'name' => 'Onboarding Co Ltd',
            'timezone' => 'Europe/London',
            'alert_email' => 'alerts@example.test',
            'require_two_factor' => false,
        ])
        ->assertRedirect(route('onboarding.show'));

    expect($tenant->fresh()->timezone)->toBe('Europe/London')
        ->and($tenant->fresh()->name)->toBe('Onboarding Co Ltd')
        ->and(data_get($tenant->fresh()->settings, 'alerts.email'))->toBe('alerts@example.test');

    $this->actingAs($user)
        ->post(route('onboarding.plan'))
        ->assertRedirect(route('onboarding.show'));

    $this->actingAs($user)
        ->post(route('onboarding.whmcs'))
        ->assertRedirect(route('onboarding.show'));

    expect($tenant->fresh()->onboarding_completed_at)->toBeNull();

    $integration = WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $user->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => now('UTC'),
    ]);

    DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) str()->uuid(),
        'schema_version' => '1',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'analysis_completed_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.0']],
        'payload_sha256' => hash('sha256', 'registration-onboarding'),
    ]);

    $this->actingAs($user)
        ->post(route('onboarding.first-scan'))
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
            ->where('onboarding.steps.whmcs', false)
            ->where('onboarding.steps.first_scan', false));
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

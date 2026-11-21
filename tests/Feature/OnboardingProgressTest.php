<?php

use App\Models\Plan;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\OnboardingProgress;
use App\Services\Tenancy\TenantProvisioningService;
use Carbon\CarbonImmutable;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

function onboardingOwnerFixture(): array
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Progress Co',
        'country_code' => 'US',
        'timezone' => 'America/New_York',
    ]);

    return [$user, $tenant];
}

test('workspace details from registration mark the workspace step complete', function () {
    [, $tenant] = onboardingOwnerFixture();

    $progress = app(OnboardingProgress::class)->forTenant($tenant->fresh());

    expect($progress['steps']['workspace'])->toBeTrue()
        ->and($progress['steps']['plan'])->toBeFalse()
        ->and($progress['steps']['whmcs'])->toBeFalse()
        ->and($progress['needed'])->toBeTrue()
        ->and($progress['current_step'])->toBe('plan');
});

test('an active subscription marks the plan step complete on the dashboard', function () {
    [$user, $tenant] = onboardingOwnerFixture();

    $plan = Plan::query()->where('code', 'growth')->first()
        ?? Plan::factory()->create(['code' => 'growth', 'name' => 'Growth']);

    TenantSubscription::query()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'plan_code' => $plan->code,
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => $plan->price_monthly_minor,
        'entitlements' => $plan->entitlements ?? ['limits' => ['members' => 10]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addMonth(),
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('onboarding.needed', true)
            ->where('onboarding.steps.workspace', true)
            ->where('onboarding.steps.plan', true)
            ->where('onboarding.steps.whmcs', false));
});

test('a trial subscription also counts as the plan step', function () {
    [$user, $tenant] = onboardingOwnerFixture();

    TenantSubscription::query()->create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'starter',
        'billing_mode' => 'automated',
        'status' => 'trialing',
        'currency' => 'USD',
        'unit_amount_minor' => 1900,
        'entitlements' => ['limits' => ['members' => 3]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC'),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addDays(14),
        'created_by_user_id' => $user->id,
    ]);

    $progress = app(OnboardingProgress::class)->forTenant($tenant->fresh());

    expect($progress['steps']['plan'])->toBeTrue();
});

test('an integration marks the whmcs step complete and finishes onboarding when all steps are done', function () {
    [$user, $tenant] = onboardingOwnerFixture();

    TenantSubscription::query()->create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'growth',
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'entitlements' => ['limits' => ['members' => 10]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC'),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addMonth(),
        'created_by_user_id' => $user->id,
    ]);

    WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $user->id,
        'name' => 'Primary WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('onboarding.needed', false)
            ->where('onboarding.steps.workspace', true)
            ->where('onboarding.steps.plan', true)
            ->where('onboarding.steps.whmcs', true));

    expect($tenant->fresh()->onboarding_completed_at)->not->toBeNull();
});

test('onboarding wizard skips completed steps derived from real state', function () {
    [$user] = onboardingOwnerFixture();

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->where('currentStep', 'plan')
            ->where('steps.workspace', true)
            ->where('steps.plan', false));
});

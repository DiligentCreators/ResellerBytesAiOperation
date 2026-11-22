<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Plan;
use App\Models\Tenant;
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
        ->and($progress['steps']['first_scan'])->toBeFalse()
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
        'unit_amount_minor' => $plan->price_monthly_minor ?? 1000,
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
            ->where('onboarding.steps.whmcs', false)
            ->where('onboarding.steps.first_scan', false));
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

function onboardingWhmcsFixture(User $user, Tenant $tenant): WhmcsIntegration
{
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

    return WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $user->id,
        'name' => 'Primary WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => now('UTC'),
    ]);
}

test('a heartbeat marks the whmcs step and an analyzed scan unlocks first scan summary', function () {
    [$user, $tenant] = onboardingOwnerFixture();
    $integration = onboardingWhmcsFixture($user, $tenant);

    $progress = app(OnboardingProgress::class)->forTenant($tenant->fresh());
    expect($progress['steps']['whmcs'])->toBeTrue()
        ->and($progress['steps']['first_scan'])->toBeFalse()
        ->and($progress['current_step'])->toBe('first_scan')
        ->and($progress['first_scan']['ready'])->toBeFalse();

    $run = DiagnosticRun::query()->create([
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
        'payload_sha256' => hash('sha256', 'onboarding-progress'),
    ]);

    DiagnosticFinding::query()->create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'fp-onboarding'),
        'source' => 'rule',
        'category' => 'cron',
        'severity' => 'warning',
        'title' => 'Cron late',
        'summary' => 'Cron is late.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
        'fix_mode' => 'one_click',
    ]);

    $progress = app(OnboardingProgress::class)->forTenant($tenant->fresh());
    expect($progress['first_scan']['ready'])->toBeTrue()
        ->and($progress['first_scan']['issues_count'])->toBe(1)
        ->and($progress['first_scan']['one_click_count'])->toBe(1)
        ->and($progress['first_scan']['has_analyzed_run'])->toBeTrue()
        ->and($progress['needed'])->toBeTrue();

    $this->actingAs($user)
        ->post(route('onboarding.first-scan'))
        ->assertRedirect(route('dashboard'));

    expect($tenant->fresh()->onboarding_completed_at)->not->toBeNull();
});

test('a received run with rule findings unlocks first scan without AI analysis', function () {
    [$user, $tenant] = onboardingOwnerFixture();
    $integration = onboardingWhmcsFixture($user, $tenant);

    $run = DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) str()->uuid(),
        'schema_version' => '1',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.0']],
        'payload_sha256' => hash('sha256', 'onboarding-rule-only'),
    ]);

    DiagnosticFinding::query()->create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'fp-onboarding-rule'),
        'source' => 'rule',
        'category' => 'cron',
        'severity' => 'warning',
        'title' => 'Cron late',
        'summary' => 'Cron is late.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
        'fix_mode' => 'one_click',
    ]);

    $progress = app(OnboardingProgress::class)->forTenant($tenant->fresh());
    expect($progress['first_scan']['ready'])->toBeTrue()
        ->and($progress['first_scan']['issues_count'])->toBe(1)
        ->and($progress['first_scan']['one_click_count'])->toBe(1)
        ->and($progress['first_scan']['has_analyzed_run'])->toBeFalse();

    $this->actingAs($user)
        ->post(route('onboarding.first-scan'))
        ->assertRedirect(route('dashboard'));

    expect($tenant->fresh()->onboarding_completed_at)->not->toBeNull();
});

test('a queued run keeps first scan waiting while AI analysis is in flight', function () {
    [$user, $tenant] = onboardingOwnerFixture();
    $integration = onboardingWhmcsFixture($user, $tenant);

    DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) str()->uuid(),
        'schema_version' => '1',
        'addon_version' => '1.0.0',
        'status' => 'queued',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.0']],
        'payload_sha256' => hash('sha256', 'onboarding-queued'),
    ]);

    $progress = app(OnboardingProgress::class)->forTenant($tenant->fresh());
    expect($progress['first_scan']['ready'])->toBeFalse()
        ->and($progress['first_scan']['has_analyzed_run'])->toBeFalse();
});

test('an analysis_failed run unlocks first scan with rule findings', function () {
    [$user, $tenant] = onboardingOwnerFixture();
    $integration = onboardingWhmcsFixture($user, $tenant);

    $run = DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) str()->uuid(),
        'schema_version' => '1',
        'addon_version' => '1.0.0',
        'status' => 'analysis_failed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.0']],
        'payload_sha256' => hash('sha256', 'onboarding-failed'),
    ]);

    DiagnosticFinding::query()->create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'fp-onboarding-failed'),
        'source' => 'rule',
        'category' => 'security',
        'severity' => 'critical',
        'title' => 'TLS weak',
        'summary' => 'Weak ciphers.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
        'fix_mode' => 'guided',
    ]);

    $progress = app(OnboardingProgress::class)->forTenant($tenant->fresh());
    expect($progress['first_scan']['ready'])->toBeTrue()
        ->and($progress['first_scan']['issues_count'])->toBe(1)
        ->and($progress['first_scan']['one_click_count'])->toBe(0)
        ->and($progress['first_scan']['has_analyzed_run'])->toBeFalse();
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
            ->where('steps.plan', false)
            ->where('steps.whmcs', false)
            ->where('steps.first_scan', false));
});

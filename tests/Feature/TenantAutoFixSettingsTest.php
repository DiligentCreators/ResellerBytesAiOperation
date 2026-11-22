<?php

use App\Models\Plan;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use Carbon\CarbonImmutable;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

it('shows the auto-fix settings page for workspace owners', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Auto Fix Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $plan = Plan::factory()->create([
        'code' => 'growth-autofix',
        'name' => 'Growth',
        'entitlements' => [
            'fixes.one_click' => true,
            'limits' => ['members' => 10, 'integrations' => 3],
        ],
    ]);

    TenantSubscription::query()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'plan_code' => $plan->code,
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 1000,
        'entitlements' => $plan->entitlements,
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addMonth(),
        'created_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('tenants.auto-fix.show', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tenants/auto-fix')
            ->where('proEnabled', true)
            ->where('tenant.id', $tenant->id));
});

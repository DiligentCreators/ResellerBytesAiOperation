<?php

use App\Models\CentralStaffAccess;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;

it('passes billing_period interval for each subscription on the admin overview', function (): void {
    $granter = User::factory()->create(['email_verified_at' => now()]);
    $staff = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['billing'],
        'granted_by_user_id' => $granter->id,
    ]);

    $monthlyPlan = Plan::factory()->create([
        'code' => 'admin-interval-monthly',
        'price_monthly_minor' => 14900,
        'price_yearly_minor' => 149000,
    ]);
    $yearlyPlan = Plan::factory()->create([
        'code' => 'admin-interval-yearly',
        'price_monthly_minor' => 14900,
        'price_yearly_minor' => 149000,
    ]);

    $monthlyTenant = Tenant::create([
        'name' => 'Admin Interval Monthly',
        'slug' => 'admin-int-m-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $yearlyTenant = Tenant::create([
        'name' => 'Admin Interval Yearly',
        'slug' => 'admin-int-y-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $monthly = TenantSubscription::query()->create([
        'tenant_id' => $monthlyTenant->id,
        'plan_id' => $monthlyPlan->id,
        'plan_code' => $monthlyPlan->code,
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 14900,
        'charge_period' => 'monthly',
        'entitlements' => $monthlyPlan->entitlements ?? ['limits' => ['members' => 5]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addMonth(),
    ]);

    $yearly = TenantSubscription::query()->create([
        'tenant_id' => $yearlyTenant->id,
        'plan_id' => $yearlyPlan->id,
        'plan_code' => $yearlyPlan->code,
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 149000,
        'charge_period' => 'yearly',
        'entitlements' => $yearlyPlan->entitlements ?? ['limits' => ['members' => 5]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addYear(),
    ]);

    $this->actingAs($staff)
        ->get(route('platform.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/index')
            ->has('subscriptions', 2)
            ->where('subscriptions', function ($subscriptions) use ($monthly, $yearly): bool {
                $byId = collect($subscriptions)->keyBy('id');

                return ($byId[$monthly->id]['billing_period'] ?? null) === 'monthly'
                    && ($byId[$yearly->id]['billing_period'] ?? null) === 'yearly';
            }));
});

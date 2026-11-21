<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Billing\BackfillTenantSubscriptionChargePeriods;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('backfills null charge_period from plan price inference and leaves existing values alone', function (): void {
    $monthlyPlan = Plan::factory()->create([
        'code' => 'backfill-monthly',
        'price_monthly_minor' => 4900,
        'price_yearly_minor' => 49000,
    ]);
    $yearlyPlan = Plan::factory()->create([
        'code' => 'backfill-yearly',
        'price_monthly_minor' => 14900,
        'price_yearly_minor' => 149000,
    ]);

    $monthlyTenant = Tenant::create([
        'name' => 'Backfill Monthly',
        'slug' => 'bf-m-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $yearlyTenant = Tenant::create([
        'name' => 'Backfill Yearly',
        'slug' => 'bf-y-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $presetTenant = Tenant::create([
        'name' => 'Backfill Preset',
        'slug' => 'bf-p-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $nullMonthly = TenantSubscription::query()->create([
        'tenant_id' => $monthlyTenant->id,
        'plan_id' => $monthlyPlan->id,
        'plan_code' => $monthlyPlan->code,
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'charge_period' => null,
        'entitlements' => ['limits' => ['members' => 5]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addMonth(),
    ]);
    $nullYearly = TenantSubscription::query()->create([
        'tenant_id' => $yearlyTenant->id,
        'plan_id' => $yearlyPlan->id,
        'plan_code' => $yearlyPlan->code,
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 149000,
        'charge_period' => null,
        'entitlements' => ['limits' => ['members' => 5]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addYear(),
    ]);
    $preset = TenantSubscription::query()->create([
        'tenant_id' => $presetTenant->id,
        'plan_id' => $yearlyPlan->id,
        'plan_code' => $yearlyPlan->code,
        'billing_mode' => 'automated',
        'status' => 'active',
        'currency' => 'USD',
        // Price would infer monthly, but stored yearly must stay.
        'unit_amount_minor' => 14900,
        'charge_period' => 'yearly',
        'entitlements' => ['limits' => ['members' => 5]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addYear(),
    ]);

    // Ensure nulls are really null at the DB layer (create may omit the column).
    DB::table('tenant_subscriptions')->whereIn('id', [$nullMonthly->id, $nullYearly->id])->update([
        'charge_period' => null,
    ]);

    $first = app(BackfillTenantSubscriptionChargePeriods::class)();
    expect($first)->toBe(2)
        ->and($nullMonthly->fresh()->charge_period)->toBe('monthly')
        ->and($nullYearly->fresh()->charge_period)->toBe('yearly')
        ->and($preset->fresh()->charge_period)->toBe('yearly');

    $second = app(BackfillTenantSubscriptionChargePeriods::class)();
    expect($second)->toBe(0)
        ->and($nullMonthly->fresh()->charge_period)->toBe('monthly')
        ->and($nullYearly->fresh()->charge_period)->toBe('yearly')
        ->and($preset->fresh()->charge_period)->toBe('yearly');
});

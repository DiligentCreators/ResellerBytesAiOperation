<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;

it('backfills missing fix entitlement flags without changing prices or manual edits', function () {
    $this->seed(PlanSeeder::class);

    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $business = Plan::query()->where('code', 'business')->firstOrFail();

    $starterMonthly = fake()->numberBetween(1000, 5000);
    $growthMonthly = fake()->numberBetween(6000, 12000);
    $businessMonthly = fake()->numberBetween(13000, 20000);

    $starter->forceFill([
        'price_monthly_minor' => $starterMonthly,
        'price_yearly_minor' => $starterMonthly * 10,
        'is_active' => true,
        'entitlements' => [
            'diagnostics.analysis' => true,
            'limits' => ['members' => 3],
            // Intentionally omit fixes.* and history_days so the backfill fills them.
        ],
    ])->save();

    $growth->forceFill([
        'price_monthly_minor' => $growthMonthly,
        'price_yearly_minor' => $growthMonthly * 10,
        'entitlements' => [
            'diagnostics.analysis' => true,
            'fixes.one_click' => false, // manual edit must win
            'limits' => ['members' => 10, 'history_days' => 120], // manual history wins
        ],
    ])->save();

    $business->forceFill([
        'price_monthly_minor' => $businessMonthly,
        'price_yearly_minor' => $businessMonthly * 10,
        'entitlements' => [
            'diagnostics.analysis' => true,
            'limits' => ['members' => 25],
        ],
    ])->save();

    $migration = require database_path('migrations/2026_11_22_000018_backfill_fix_entitlements_on_plans.php');
    $migration->up();
    $migration->up(); // idempotent

    $starter = $starter->fresh();
    $growth = $growth->fresh();
    $business = $business->fresh();

    expect($starter->price_monthly_minor)->toBe($starterMonthly)
        ->and($starter->price_yearly_minor)->toBe($starterMonthly * 10)
        ->and($starter->entitlements['fixes.guided'])->toBeTrue()
        ->and($starter->entitlements['fixes.one_click'])->toBeFalse()
        ->and($starter->entitlements['fixes.auto'])->toBeFalse()
        ->and($starter->entitlements['limits']['history_days'])->toBe(90)
        ->and($starter->entitlements['limits']['members'])->toBe(3);

    expect($growth->price_monthly_minor)->toBe($growthMonthly)
        ->and($growth->entitlements['fixes.one_click'])->toBeFalse()
        ->and($growth->entitlements['fixes.auto'])->toBeFalse()
        ->and($growth->entitlements['alerts.channels'])->toBeTrue()
        ->and($growth->entitlements['reports.value'])->toBeFalse()
        ->and($growth->entitlements['limits']['history_days'])->toBe(120)
        ->and($growth->entitlements['limits']['members'])->toBe(10);

    expect($business->price_monthly_minor)->toBe($businessMonthly)
        ->and($business->entitlements['fixes.auto'])->toBeTrue()
        ->and($business->entitlements['reports.value'])->toBeTrue()
        ->and($business->entitlements['limits']['history_days'])->toBe(365)
        ->and($business->entitlements['limits']['members'])->toBe(25);
});

it('keeps plan_tiers mapping aligned with catalog plan codes', function () {
    $codes = config('plan_tiers.codes');
    $entitlements = config('plan_tiers.entitlements');

    expect($codes['starter'])->toContain('starter')
        ->and($codes['growth'])->toContain('growth')
        ->and($codes['pro'])->toContain('business')
        ->and($codes['pro'])->toContain('enterprise')
        ->and($entitlements['starter']['fixes.auto'])->toBeFalse()
        ->and($entitlements['growth']['fixes.auto'])->toBeFalse()
        ->and($entitlements['pro']['fixes.auto'])->toBeTrue()
        ->and($entitlements['starter']['reports.value'])->toBeFalse()
        ->and($entitlements['growth']['reports.value'])->toBeFalse()
        ->and($entitlements['pro']['reports.value'])->toBeTrue()
        ->and($entitlements['starter']['limits']['history_days'])->toBe(90)
        ->and($entitlements['growth']['limits']['history_days'])->toBe(365)
        ->and($entitlements['pro']['limits']['history_days'])->toBe(365);
});

it('aligns growth reports.value to false without touching prices or other keys', function () {
    $this->seed(PlanSeeder::class);

    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $business = Plan::query()->where('code', 'business')->firstOrFail();

    $growthMonthly = fake()->numberBetween(6000, 12000);
    $businessMonthly = fake()->numberBetween(13000, 20000);

    $growth->forceFill([
        'price_monthly_minor' => $growthMonthly,
        'price_yearly_minor' => $growthMonthly * 10,
        'entitlements' => array_replace_recursive((array) $growth->entitlements, [
            'reports.value' => true,
            'fixes.one_click' => true,
            'alerts.channels' => true,
        ]),
    ])->save();

    $business->forceFill([
        'price_monthly_minor' => $businessMonthly,
        'entitlements' => array_replace_recursive((array) $business->entitlements, [
            'reports.value' => true,
        ]),
    ])->save();

    $migration = require database_path('migrations/2026_11_22_000019_align_growth_reports_value_entitlement.php');
    $migration->up();
    $migration->up(); // idempotent

    $growth = $growth->fresh();
    $business = $business->fresh();

    expect($growth->price_monthly_minor)->toBe($growthMonthly)
        ->and($growth->price_yearly_minor)->toBe($growthMonthly * 10)
        ->and($growth->entitlements['reports.value'])->toBeFalse()
        ->and($growth->entitlements['fixes.one_click'])->toBeTrue()
        ->and($growth->entitlements['alerts.channels'])->toBeTrue()
        ->and($business->entitlements['reports.value'])->toBeTrue()
        ->and($business->price_monthly_minor)->toBe($businessMonthly);

    $migration->down();

    expect($growth->fresh()->entitlements['reports.value'])->toBeTrue()
        ->and($business->fresh()->entitlements['reports.value'])->toBeTrue();
});

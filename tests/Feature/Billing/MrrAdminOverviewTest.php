<?php

use App\Models\CentralStaffAccess;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\MrrCalculator;
use Carbon\CarbonImmutable;

function mrrAdminStaff(): User
{
    $granter = User::factory()->create(['email_verified_at' => now()]);
    $staff = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => ['billing'],
        'granted_by_user_id' => $granter->id,
    ]);

    return $staff;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function mrrAdminSubscription(Plan $plan, int $unitAmountMinor, string $status = 'active', array $overrides = []): TenantSubscription
{
    $tenant = Tenant::create([
        'name' => 'MRR Tenant '.uniqid(),
        'slug' => 'mrr-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    return TenantSubscription::query()->create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'plan_code' => $plan->code,
        'billing_mode' => 'automated',
        'status' => $status,
        'currency' => 'USD',
        'unit_amount_minor' => $unitAmountMinor,
        'entitlements' => $plan->entitlements ?? ['limits' => ['members' => 5]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addMonth(),
    ], $overrides));
}

it('exposes normalised MRR on the platform billing overview and excludes non-paying statuses', function (): void {
    $staff = mrrAdminStaff();

    $monthlyA = Plan::factory()->create([
        'code' => 'mrr-monthly-a',
        'price_monthly_minor' => 4900,
        'price_yearly_minor' => 49000,
    ]);
    $monthlyB = Plan::factory()->create([
        'code' => 'mrr-monthly-b',
        'price_monthly_minor' => 14900,
        'price_yearly_minor' => 149000,
    ]);
    $yearlyA = Plan::factory()->create([
        'code' => 'mrr-yearly-a',
        'price_monthly_minor' => 14900,
        'price_yearly_minor' => 149000,
    ]);
    $yearlyB = Plan::factory()->create([
        'code' => 'mrr-yearly-b',
        'price_monthly_minor' => 49900,
        'price_yearly_minor' => 499000,
    ]);

    // Counted mix (one still cancel_at_period_end — remains counted while active).
    mrrAdminSubscription($monthlyA, 4900, 'active', ['cancel_at_period_end' => true]);
    mrrAdminSubscription($monthlyB, 14900);
    mrrAdminSubscription($yearlyA, 149000);
    mrrAdminSubscription($yearlyB, 499000);

    // Exclusions: canceled, incomplete, and ended (period over + canceled_at).
    mrrAdminSubscription($yearlyB, 499000, 'canceled', [
        'canceled_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);
    mrrAdminSubscription($yearlyA, 149000, 'incomplete');
    mrrAdminSubscription($monthlyB, 14900, 'canceled', [
        'canceled_at' => CarbonImmutable::now('UTC')->subDay(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);

    // 4900 + 14900 + 149000/12 + 499000/12 = 73800
    expect(app(MrrCalculator::class)->approximateMrrMinor())->toBe(73800);

    $this->actingAs($staff)
        ->get(route('platform.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/index')
            ->where('summary.mrr_minor', 73800));
});

it('rounds a lone yearly hundred-dollar subscription to eight thirty-three on the admin page', function (): void {
    $staff = mrrAdminStaff();

    $plan = Plan::factory()->create([
        'code' => 'mrr-round',
        'price_monthly_minor' => 1000,
        'price_yearly_minor' => 10000,
    ]);
    mrrAdminSubscription($plan, 10000);

    $this->actingAs($staff)
        ->get(route('platform.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/index')
            ->where('summary.mrr_minor', 833));
});

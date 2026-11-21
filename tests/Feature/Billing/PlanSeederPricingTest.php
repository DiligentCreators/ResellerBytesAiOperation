<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Support\BillingCustomerMessages;
use Database\Seeders\PlanSeeder;
use InvalidArgumentException;

it('seeds unpriced inactive purchasable tiers and an active contact-sales tier on an empty catalog', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->count())->toBe(4);

    foreach (['starter', 'growth', 'business'] as $code) {
        $plan = Plan::query()->where('code', $code)->firstOrFail();

        expect($plan->price_monthly_minor)->toBeNull()
            ->and($plan->price_yearly_minor)->toBeNull()
            ->and($plan->is_active)->toBeFalse()
            ->and($plan->prices_confirmed_at)->toBeNull()
            ->and($plan->is_contact_sales)->toBeFalse();
    }

    $enterprise = Plan::query()->where('code', 'enterprise')->firstOrFail();

    expect($enterprise->is_active)->toBeTrue()
        ->and($enterprise->is_contact_sales)->toBeTrue()
        ->and($enterprise->price_monthly_minor)->toBeNull()
        ->and($enterprise->price_yearly_minor)->toBeNull();
});

it('preserves admin-set prices, active flags, and names when seeded twice', function () {
    $this->seed(PlanSeeder::class);

    $monthly = fake()->numberBetween(2500, 9000);
    $yearly = $monthly * 10;
    $confirmedAt = now()->subDay()->startOfSecond();

    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $starter->forceFill([
        'name' => 'Starter Pro Admin',
        'description' => 'Admin-edited description',
        'price_monthly_minor' => $monthly,
        'price_yearly_minor' => $yearly,
        'is_active' => true,
        'prices_confirmed_at' => $confirmedAt,
        'entitlements' => array_replace_recursive($starter->entitlements ?? [], [
            'limits' => ['members' => 7],
            'support_level' => 'admin_custom',
        ]),
    ])->save();

    $this->seed(PlanSeeder::class);

    $starter = $starter->fresh();

    expect($starter->name)->toBe('Starter Pro Admin')
        ->and($starter->description)->toBe('Admin-edited description')
        ->and($starter->price_monthly_minor)->toBe($monthly)
        ->and($starter->price_yearly_minor)->toBe($yearly)
        ->and($starter->is_active)->toBeTrue()
        ->and($starter->prices_confirmed_at?->toDateTimeString())->toBe($confirmedAt->toDateTimeString())
        ->and($starter->entitlements['limits']['members'])->toBe(7)
        ->and($starter->entitlements['support_level'])->toBe('admin_custom')
        ->and($starter->entitlements['limits'])->toHaveKey('integrations');
});

it('merges missing entitlement keys on existing plans without overwriting admin values', function () {
    $this->seed(PlanSeeder::class);

    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $growth->forceFill([
        'entitlements' => [
            'diagnostics.analysis' => true,
            'limits' => [
                'members' => 99,
            ],
            'support_level' => 'custom',
        ],
    ])->save();

    $this->seed(PlanSeeder::class);

    $growth = $growth->fresh();

    expect($growth->entitlements['limits']['members'])->toBe(99)
        ->and($growth->entitlements['support_level'])->toBe('custom')
        ->and($growth->entitlements['diagnostics.reanalyze'])->toBeTrue()
        ->and($growth->entitlements['safe_actions.enabled'])->toBeTrue()
        ->and($growth->entitlements['limits']['integrations'])->toBe(3);
});

it('seeds plans without prices and blocks checkout until priced', function () {
    $this->seed(PlanSeeder::class);

    $starter = Plan::query()->where('code', 'starter')->firstOrFail();

    expect($starter->price_monthly_minor)->toBeNull()
        ->and($starter->price_yearly_minor)->toBeNull()
        ->and($starter->is_active)->toBeFalse()
        ->and($starter->isCheckoutable())->toBeFalse();

    $tenant = Tenant::create([
        'name' => 'Unpriced Tenant',
        'slug' => 'unpriced-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    // Force-active without prices still cannot be purchased.
    $starter->forceFill(['is_active' => true])->save();

    expect(fn () => app(CheckoutService::class)->begin(
        $tenant->id,
        $owner,
        $starter->fresh(),
        'monthly',
        [
            'company_name' => 'Unpriced Co',
            'billing_email' => 'unpriced@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    ))->toThrow(InvalidArgumentException::class, 'billing_plan_price_missing');

    $this->actingAs($owner)
        ->from(route('billing.plans'))
        ->post(route('billing.checkout.store', [$tenant, $starter]), [
            'period' => 'monthly',
            'company_name' => 'Unpriced Co',
            'billing_email' => 'unpriced@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'checkout' => BillingCustomerMessages::CHECKOUT_PLAN_NEEDS_PRICING,
        ]);
});

it('allows platform admins to set plan prices and then purchase', function () {
    $this->seed(PlanSeeder::class);

    $monthly = fake()->numberBetween(2000, 8000);
    $yearly = $monthly * 10;

    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $starter->forceFill([
        'price_monthly_minor' => $monthly,
        'price_yearly_minor' => $yearly,
        'is_active' => true,
        'prices_confirmed_at' => now(),
    ])->save();

    expect($starter->fresh()->isCheckoutable())->toBeTrue()
        ->and($starter->fresh()->price_monthly_minor)->toBe($monthly);

    $tenant = Tenant::create([
        'name' => 'Priced Tenant',
        'slug' => 'priced-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    config()->set('billing.default_gateway', 'sandbox');

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $owner,
        $starter->fresh(),
        'monthly',
        [
            'company_name' => 'Priced Co',
            'billing_email' => 'priced@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($result['invoice']->amount_due_minor)->toBe($monthly)
        ->and($result['subscription']->unit_amount_minor)->toBe($monthly);
});

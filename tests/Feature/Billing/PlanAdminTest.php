<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\CentralStaffAccess;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['operations.owner_email' => 'owner@example.test']);
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
});

function planAdminOwner(): User
{
    return User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
}

function planAdminStaff(array $permissions): User
{
    $grantor = User::factory()->create(['email' => 'grantor-'.uniqid().'@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => $permissions,
        'granted_by_user_id' => $grantor->id,
    ]);

    return $staff;
}

function planPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'new-plan-'.uniqid(),
        'name' => 'New Plan',
        'description' => 'Desc',
        'price_monthly' => '49.00',
        'price_yearly' => '490.00',
        'currency' => 'usd',
        'trial_enabled' => true,
        'trial_days' => 14,
        'sort_order' => 50,
        'monthly_active' => true,
        'yearly_active' => true,
        'is_active' => true,
        'is_public' => true,
        'is_contact_sales' => false,
        'features' => ['Feature one', 'Feature two'],
        'entitlements' => [
            'limits' => [
                'members' => 5,
                'integrations' => 1,
                'diagnostics_per_period' => 50,
                'retention_days' => 30,
            ],
            'support_level' => 'email',
        ],
    ], $overrides);
}

it('allows super admin and billing.settings.manage to create plans', function () {
    $owner = planAdminOwner();
    $manager = planAdminStaff(['billing.settings.manage']);

    $this->actingAs($owner)
        ->post(route('platform.billing.plans.store'), planPayload(['code' => 'owner-plan']))
        ->assertRedirect();

    $this->actingAs($manager)
        ->post(route('platform.billing.plans.store'), planPayload(['code' => 'manager-plan']))
        ->assertRedirect();

    expect(Plan::query()->where('code', 'owner-plan')->exists())->toBeTrue()
        ->and(Plan::query()->where('code', 'manager-plan')->value('price_monthly_minor'))->toBe(4900)
        ->and(Plan::query()->where('code', 'manager-plan')->value('currency'))->toBe('USD')
        ->and(Plan::query()->where('code', 'manager-plan')->value('prices_confirmed_at'))->not->toBeNull();
});

it('allows billing-only staff to view plans but forbids mutations', function () {
    $staff = planAdminStaff(['billing']);
    $plan = Plan::factory()->create();

    $this->actingAs($staff)
        ->get(route('platform.billing.plans.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/plans')
            ->where('canManage', false));

    $this->actingAs($staff)
        ->post(route('platform.billing.plans.store'), planPayload())
        ->assertForbidden();

    $this->actingAs($staff)
        ->put(route('platform.billing.plans.update', $plan), planPayload(['code' => $plan->code]))
        ->assertForbidden();

    $this->actingAs($staff)
        ->post(route('platform.billing.plans.deactivate', $plan))
        ->assertForbidden();
});

it('deactivates and reactivates a plan with audit events', function () {
    $owner = planAdminOwner();
    $plan = Plan::factory()->create(['is_active' => true, 'is_public' => true]);

    $this->actingAs($owner)
        ->post(route('platform.billing.plans.deactivate', $plan))
        ->assertRedirect();

    expect($plan->fresh()->is_active)->toBeFalse();

    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => collect($plans)->every(
                fn ($p) => (int) $p['id'] !== (int) $plan->id,
            )));

    $this->actingAs($owner)
        ->post(route('platform.billing.plans.reactivate', $plan))
        ->assertRedirect();

    expect($plan->fresh()->is_active)->toBeTrue()
        ->and(DB::table('audit_events')->where('event_type', 'billing.plan.deactivated')->exists())->toBeTrue()
        ->and(DB::table('audit_events')->where('event_type', 'billing.plan.reactivated')->exists())->toBeTrue();
});

it('blocks hard delete when the plan is referenced and allows it when unreferenced', function () {
    $owner = planAdminOwner();
    $referenced = Plan::factory()->create();
    $free = Plan::factory()->create();

    $tenant = Tenant::create([
        'name' => 'Ref Tenant',
        'slug' => 'ref-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);

    TenantSubscription::query()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $referenced->id,
        'plan_code' => $referenced->code,
        'billing_mode' => 'automated',
        'status' => 'canceled',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'charge_period' => 'monthly',
        'entitlements' => $referenced->entitlements,
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC'),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->addMonth(),
    ]);

    $this->actingAs($owner)
        ->delete(route('platform.billing.plans.destroy', $referenced))
        ->assertSessionHasErrors('plan');

    expect(Plan::query()->whereKey($referenced->id)->exists())->toBeTrue();

    $this->actingAs($owner)
        ->delete(route('platform.billing.plans.destroy', $free))
        ->assertRedirect();

    expect(Plan::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('event_type', 'billing.plan.deleted')->exists())->toBeTrue();
});

it('validates the marketing feature list', function () {
    $owner = planAdminOwner();
    $tooMany = array_fill(0, 21, 'line');

    $this->actingAs($owner)
        ->post(route('platform.billing.plans.store'), planPayload([
            'features' => $tooMany,
        ]))
        ->assertSessionHasErrors('features');

    $this->actingAs($owner)
        ->post(route('platform.billing.plans.store'), planPayload([
            'features' => [str_repeat('x', 121)],
        ]))
        ->assertSessionHasErrors('features.0');
});

it('audits plan create and update with changed fields', function () {
    $owner = planAdminOwner();

    $this->actingAs($owner)
        ->post(route('platform.billing.plans.store'), planPayload(['code' => 'audit-plan', 'name' => 'Audit']))
        ->assertRedirect();

    $plan = Plan::query()->where('code', 'audit-plan')->firstOrFail();
    $created = DB::table('audit_events')->where('event_type', 'billing.plan.created')->first();
    expect($created)->not->toBeNull();

    $this->actingAs($owner)
        ->put(route('platform.billing.plans.update', $plan), planPayload([
            'code' => 'audit-plan',
            'name' => 'Audit Renamed',
            'price_monthly' => '59.00',
            'price_yearly' => '590.00',
        ]))
        ->assertRedirect();

    $updated = DB::table('audit_events')->where('event_type', 'billing.plan.updated')->first();
    $metadata = json_decode($updated->metadata, true);
    expect($metadata['changed_fields'])->toHaveKey('name')
        ->and($metadata['changed_fields']['name']['new'])->toBe('Audit Renamed')
        ->and($metadata['changed_fields'])->toHaveKey('price_monthly_minor');
});

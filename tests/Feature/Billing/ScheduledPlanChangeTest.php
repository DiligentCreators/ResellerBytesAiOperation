<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\SubscriptionManagementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Artisan;

function scheduledPlanFixture(): array
{
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Scheduled Change Tenant',
        'slug' => 'sched-change-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $outsider = User::factory()->create(['email_verified_at' => now()]);

    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $tenant->memberships()->create([
        'user_id' => $viewer->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $starter = Plan::query()->where('code', 'starter')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $owner, $growth, 'yearly', [
        'company_name' => 'Sched Co',
        'billing_email' => 'sched@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    return [$tenant, $owner, $viewer, $outsider, $growth, $starter, $checkout['subscription']->fresh()];
}

it('schedules a downgrade without checkout invoice or gateway call', function (): void {
    [$tenant, $owner, , , $growth, $starter, $current] = scheduledPlanFixture();
    $invoiceCountBefore = TenantBillingInvoice::query()->where('tenant_id', $tenant->id)->count();

    $result = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        [
            'company_name' => 'Sched Co',
            'billing_email' => 'sched@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($result['mode'])->toBe('downgrade')
        ->and($result['checkout_url'])->toBeNull()
        ->and($result['invoice'])->toBeNull()
        ->and($current->fresh()->plan_code)->toBe('growth')
        ->and($current->fresh()->status)->toBe('active')
        ->and($current->fresh()->scheduled_plan_id)->toBe($starter->id)
        ->and($current->fresh()->scheduled_charge_period)->toBe('monthly')
        ->and($current->fresh()->scheduled_change_at?->equalTo($current->current_period_ends_at))->toBeTrue()
        ->and(TenantBillingInvoice::query()->where('tenant_id', $tenant->id)->count())->toBe($invoiceCountBefore)
        ->and(TenantSubscription::query()->where('tenant_id', $tenant->id)->where('status', 'incomplete')->count())->toBe(0);
});

it('schedules yearly to monthly even when the monthly plan is pricier', function (): void {
    [$tenant, $owner, , , $growth, , $current] = scheduledPlanFixture();
    $business = Plan::query()->where('code', 'business')->firstOrFail();

    // Growth yearly (~12.4k/mo normalized) → Business monthly (39.9k) is still scheduled
    // because yearly → monthly always schedules.
    $result = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $business,
        'monthly',
        [
            'company_name' => 'Sched Co',
            'billing_email' => 'sched@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($result['mode'])->toBe('downgrade')
        ->and($current->fresh()->scheduled_plan_id)->toBe($business->id)
        ->and($current->fresh()->scheduled_charge_period)->toBe('monthly')
        ->and($current->fresh()->plan_code)->toBe('growth');
});

it('exposes scheduled change props and cancels via the delete route', function (): void {
    [$tenant, $owner, , , , $starter, $current] = scheduledPlanFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        [
            'company_name' => 'Sched Co',
            'billing_email' => 'sched@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    $this->actingAs($owner)
        ->get(route('billing.settings.show', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/settings')
            ->where('scheduledChange.plan_code', 'starter')
            ->where('scheduledChange.charge_period', 'monthly')
            ->has('scheduledChange.change_at'));

    $this->actingAs($owner)
        ->delete(route('billing.subscriptions.scheduled-change.cancel', [$tenant, $current]))
        ->assertRedirect()
        ->assertSessionHas('status', 'Scheduled plan change canceled.');

    expect($current->fresh()->scheduled_plan_id)->toBeNull()
        ->and($current->fresh()->scheduled_change_at)->toBeNull();
});

it('forbids non-billing members from canceling a scheduled change and 404s outsiders', function (): void {
    [$tenant, $owner, $viewer, $outsider, , $starter, $current] = scheduledPlanFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        [
            'company_name' => 'Sched Co',
            'billing_email' => 'sched@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    $this->actingAs($viewer)
        ->delete(route('billing.subscriptions.scheduled-change.cancel', [$tenant, $current]))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->delete(route('billing.subscriptions.scheduled-change.cancel', [$tenant, $current]))
        ->assertNotFound();
});

it('applies due scheduled changes idempotently and skips cancel-at-period-end', function (): void {
    [$tenant, $owner, , , , $starter, $current] = scheduledPlanFixture();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        [
            'company_name' => 'Sched Co',
            'billing_email' => 'sched@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    $periodEnd = CarbonImmutable::parse($current->fresh()->current_period_ends_at)->utc();
    CarbonImmutable::setTestNow($periodEnd->addMinute());

    $beforeInvoices = TenantBillingInvoice::query()->where('tenant_id', $tenant->id)->count();

    Artisan::call('billing:apply-scheduled-plan-changes');
    Artisan::call('billing:apply-scheduled-plan-changes');

    expect($current->fresh()->status)->toBe('canceled')
        ->and($current->fresh()->scheduled_plan_id)->toBeNull();

    $replacement = TenantSubscription::query()
        ->where('tenant_id', $tenant->id)
        ->where('status', 'active')
        ->where('replaces_subscription_id', $current->id)
        ->first();

    expect($replacement)->not->toBeNull()
        ->and($replacement->plan_code)->toBe('starter')
        ->and($replacement->unit_amount_minor)->toBe($starter->price_monthly_minor)
        ->and($replacement->current_period_starts_at?->equalTo($periodEnd))->toBeTrue()
        ->and(TenantBillingInvoice::query()->where('tenant_id', $tenant->id)->count())->toBe($beforeInvoices + 1);

    // Not-yet-due schedule is ignored.
    $growth = Plan::query()->where('code', 'growth')->firstOrFail();
    $replacement->forceFill([
        'scheduled_plan_id' => $growth->id,
        'scheduled_charge_period' => 'yearly',
        'scheduled_change_at' => CarbonImmutable::now('UTC')->addDays(10),
    ])->save();

    Artisan::call('billing:apply-scheduled-plan-changes');
    expect($replacement->fresh()->plan_code)->toBe('starter')
        ->and($replacement->fresh()->scheduled_plan_id)->toBe($growth->id);

    // cancel_at_period_end wins: clear schedule, do not switch plan.
    $replacement->forceFill([
        'cancel_at_period_end' => true,
        'scheduled_change_at' => CarbonImmutable::now('UTC')->subMinute(),
    ])->save();

    Artisan::call('billing:apply-scheduled-plan-changes');
    expect($replacement->fresh()->plan_code)->toBe('starter')
        ->and($replacement->fresh()->status)->toBe('active')
        ->and($replacement->fresh()->scheduled_plan_id)->toBeNull();

    CarbonImmutable::setTestNow();
});

it('clears a pending scheduled change when an immediate upgrade starts', function (): void {
    [$tenant, $owner, , , , $starter, $current] = scheduledPlanFixture();
    $business = Plan::query()->where('code', 'business')->firstOrFail();

    app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $starter,
        'monthly',
        [
            'company_name' => 'Sched Co',
            'billing_email' => 'sched@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($current->fresh()->scheduled_plan_id)->not->toBeNull();

    $upgrade = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $current->id,
        $owner,
        $business,
        'yearly',
        [
            'company_name' => 'Sched Co',
            'billing_email' => 'sched@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    expect($upgrade['mode'])->toBe('upgrade')
        ->and($current->fresh()->scheduled_plan_id)->toBeNull()
        ->and($upgrade['subscription']->status)->toBe('incomplete');
});

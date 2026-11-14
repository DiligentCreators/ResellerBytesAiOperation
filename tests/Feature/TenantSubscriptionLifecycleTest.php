<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantSubscriptionLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

function subscriptionLifecycleFixture(string $role = TenantMembership::ROLE_BILLING): array
{
    $tenant = Tenant::create([
        'name' => 'Lifecycle Workspace',
        'slug' => 'lifecycle-workspace-'.strtolower($role),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $actor = User::factory()->create();
    $tenant->memberships()->create([
        'user_id' => $actor->id,
        'role' => $role,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $subscription = TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'approved-plan-snapshot',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['diagnostics.reanalyze' => true],
        'current_period_starts_at' => now()->subMonth(),
        'current_period_ends_at' => now()->subDay(),
    ]);

    return [$tenant, $actor, $subscription];
}

it('audits past-due and recovery transitions and keeps grace entitlements bounded', function () {
    [$tenant, $actor, $subscription] = subscriptionLifecycleFixture();
    $lifecycle = app(TenantSubscriptionLifecycle::class);

    $pastDue = $lifecycle->transition($tenant->id, $subscription->id, 'past_due', now()->addDays(5), $actor->id);

    expect($pastDue->status)->toBe('past_due')
        ->and($pastDue->grace_ends_at->isFuture())->toBeTrue()
        ->and(app(TenantEntitlementService::class)->activeSubscription($tenant->id)->id)->toBe($subscription->id);

    $active = $lifecycle->transition($tenant->id, $subscription->id, 'active', null, $actor->id);

    expect($active->status)->toBe('active')
        ->and($active->grace_ends_at)->toBeNull()
        ->and($tenant->auditEvents()->where('event_type', 'tenant_subscription.status_changed')->count())->toBe(2);
});

it('rejects subscription lifecycle changes from roles without billing management', function () {
    [$tenant, $actor, $subscription] = subscriptionLifecycleFixture(TenantMembership::ROLE_VIEWER);

    expect(fn () => app(TenantSubscriptionLifecycle::class)->transition(
        $tenant->id,
        $subscription->id,
        'past_due',
        now()->addDay(),
        $actor->id,
    ))->toThrow(AuthorizationException::class);

    expect($subscription->fresh()->status)->toBe('active')
        ->and($tenant->auditEvents()->where('event_type', 'tenant_subscription.status_changed')->count())->toBe(0);
});

it('requires a future grace deadline and rejects illegal terminal-state resurrection', function () {
    [$tenant, $actor, $subscription] = subscriptionLifecycleFixture();
    $lifecycle = app(TenantSubscriptionLifecycle::class);

    expect(fn () => $lifecycle->transition($tenant->id, $subscription->id, 'past_due', null, $actor->id))
        ->toThrow(InvalidArgumentException::class, 'tenant_subscription_grace_period_required');

    $canceled = $lifecycle->transition($tenant->id, $subscription->id, 'canceled', null, $actor->id);
    expect($canceled->canceled_at)->not->toBeNull();

    expect(fn () => $lifecycle->transition($tenant->id, $subscription->id, 'active', null, $actor->id))
        ->toThrow(InvalidArgumentException::class, 'tenant_subscription_transition_not_allowed');

    expect($subscription->fresh()->status)->toBe('canceled');
});

it('treats a repeated identical transition as an idempotent no-op', function () {
    [$tenant, $actor, $subscription] = subscriptionLifecycleFixture();
    $lifecycle = app(TenantSubscriptionLifecycle::class);

    $first = $lifecycle->transition($tenant->id, $subscription->id, 'past_due', now()->addDays(3), $actor->id);
    $again = $lifecycle->transition($tenant->id, $subscription->id, 'past_due', null, $actor->id);

    expect($again->id)->toBe($first->id)
        ->and($tenant->auditEvents()->where('event_type', 'tenant_subscription.status_changed')->count())->toBe(1);
});

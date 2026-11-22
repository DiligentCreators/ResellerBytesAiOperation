<?php

use App\Models\AutoFixPolicy;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\SafeActions\SafeActionRegistry;
use App\Services\Tenancy\TenantProvisioningService;
use Carbon\CarbonImmutable;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

/**
 * @param  array<string, mixed>  $entitlements
 * @return array{user: User, tenant: Tenant, integration: WhmcsIntegration}
 */
function createAutoFixPolicyWorkspace(array $entitlements = []): array
{
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Auto Fix Policy Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $plan = Plan::factory()->create([
        'code' => 'pro-autofix-'.uniqid(),
        'name' => 'Agency',
        'entitlements' => array_merge([
            'fixes.guided' => true,
            'fixes.one_click' => true,
            'fixes.bulk' => true,
            'fixes.auto' => true,
            'limits' => ['members' => 10, 'integrations' => 3],
        ], $entitlements),
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

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $user->id,
        'tenant_id' => $tenant->id,
        'name' => 'Policy WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
        'last_seen_at' => now()->subHour(),
    ]);

    return compact('user', 'tenant', 'integration');
}

it('lists only auto_allowed actions on the auto-fix settings page', function () {
    ['user' => $user, 'tenant' => $tenant] = createAutoFixPolicyWorkspace();

    $autoKeys = collect(app(SafeActionRegistry::class)->all())
        ->filter(fn (array $def): bool => ($def['auto_allowed'] ?? false) === true)
        ->keys()
        ->values()
        ->all();

    $this->actingAs($user)
        ->get(route('tenants.auto-fix.show', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tenants/auto-fix')
            ->where('proEnabled', true)
            ->has('actions', count($autoKeys))
            ->where('actions.0.key', fn ($key) => in_array($key, $autoKeys, true)));
});

it('rejects policies for forbidden non-auto action classes', function () {
    ['user' => $user, 'tenant' => $tenant] = createAutoFixPolicyWorkspace();

    $forbidden = [
        'service_unsuspend_paid',
        'invoice_generate_due',
        'order_accept_paid',
        'domain_renew_retry',
        'RefundInvoice',
        'ModuleTerminate',
    ];

    foreach ($forbidden as $actionKey) {
        $this->actingAs($user)
            ->put(route('tenants.auto-fix.update', $tenant), [
                'policies' => [[
                    'action_key' => $actionKey,
                    'integration_id' => null,
                    'enabled' => true,
                    'daily_cap' => null,
                ]],
            ])
            ->assertSessionHasErrors('policies.0.action_key');
    }

    expect(AutoFixPolicy::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('upserts an enabled auto-fix policy for an allowlisted action', function () {
    ['user' => $user, 'tenant' => $tenant, 'integration' => $integration] = createAutoFixPolicyWorkspace();

    $this->actingAs($user)
        ->put(route('tenants.auto-fix.update', $tenant), [
            'policies' => [[
                'action_key' => 'ticket_escalate_sla',
                'integration_id' => $integration->id,
                'enabled' => true,
                'daily_cap' => 5,
            ]],
        ])
        ->assertRedirect(route('tenants.auto-fix.show', $tenant))
        ->assertSessionHasNoErrors();

    $policy = AutoFixPolicy::query()->where('tenant_id', $tenant->id)->first();
    expect($policy)->not->toBeNull()
        ->and($policy->action_key)->toBe('ticket_escalate_sla')
        ->and($policy->integration_id)->toBe($integration->id)
        ->and($policy->enabled)->toBeTrue()
        ->and($policy->daily_cap)->toBe(5)
        ->and($policy->created_by_user_id)->toBe($user->id);
});

it('gates auto-fix policy updates behind the fixes.auto entitlement', function () {
    ['user' => $user, 'tenant' => $tenant] = createAutoFixPolicyWorkspace([
        'fixes.auto' => false,
    ]);

    $this->actingAs($user)
        ->put(route('tenants.auto-fix.update', $tenant), [
            'policies' => [[
                'action_key' => 'whmcs_clear_template_cache',
                'integration_id' => null,
                'enabled' => true,
                'daily_cap' => null,
            ]],
        ])
        ->assertForbidden();
});

it('shows an upgrade hint when the workspace lacks fixes.auto', function () {
    ['user' => $user, 'tenant' => $tenant] = createAutoFixPolicyWorkspace([
        'fixes.auto' => false,
    ]);

    $this->actingAs($user)
        ->get(route('tenants.auto-fix.show', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tenants/auto-fix')
            ->where('proEnabled', false));
});

it('requires owner or admin to update auto-fix policies', function () {
    ['user' => $owner, 'tenant' => $tenant] = createAutoFixPolicyWorkspace();

    $operator = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $operator->id,
        'role' => TenantMembership::ROLE_OPERATOR,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($operator)
        ->put(route('tenants.auto-fix.update', $tenant), [
            'policies' => [[
                'action_key' => 'invoice_send_overdue_reminder',
                'integration_id' => null,
                'enabled' => true,
                'daily_cap' => null,
            ]],
        ])
        ->assertForbidden();

    expect(AutoFixPolicy::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
    expect($owner->id)->not->toBe($operator->id);
});

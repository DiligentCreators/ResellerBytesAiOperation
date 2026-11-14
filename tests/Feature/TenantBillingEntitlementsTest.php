<?php

use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Tenancy\TenantBillingLedgerService;
use App\Services\Tenancy\TenantEntitlementService;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

function createTenantSubscriptionFixture(Tenant $tenant, array $overrides = []): TenantSubscription
{
    return TenantSubscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_code' => 'professional',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'entitlements' => ['diagnostics.reanalyze' => true, 'limits' => ['integrations' => 10]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ], $overrides));
}

it('resolves only active subscriptions within their paid period', function () {
    $tenant = Tenant::create(['name' => 'Entitlement Workspace', 'slug' => 'entitlement-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    $subscription = createTenantSubscriptionFixture($tenant);

    expect(app(TenantEntitlementService::class)->activeSubscription($tenant->id)->id)->toBe($subscription->id)
        ->and(app(TenantEntitlementService::class)->requireFeature($tenant->id, 'diagnostics.reanalyze')->id)->toBe($subscription->id)
        ->and(app(TenantEntitlementService::class)->requireLimit($tenant->id, 'integrations', 10)->id)->toBe($subscription->id);
});

it('allows a past-due subscription only during its explicit grace period', function () {
    $tenant = Tenant::create(['name' => 'Grace Workspace', 'slug' => 'grace-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    createTenantSubscriptionFixture($tenant, ['status' => 'past_due', 'current_period_ends_at' => now()->subDay(), 'grace_ends_at' => now()->addDay()]);

    expect(app(TenantEntitlementService::class)->activeSubscription($tenant->id)->status)->toBe('past_due');

    TenantSubscription::query()->where('tenant_id', $tenant->id)->update(['grace_ends_at' => now()->subSecond()]);
    expect(fn () => app(TenantEntitlementService::class)->activeSubscription($tenant->id))
        ->toThrow(AuthorizationException::class);
});

it('fails closed for missing subscription and unavailable features or limits', function () {
    $tenant = Tenant::create(['name' => 'No Subscription Workspace', 'slug' => 'no-subscription-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    expect(fn () => app(TenantEntitlementService::class)->activeSubscription($tenant->id))
        ->toThrow(AuthorizationException::class);

    createTenantSubscriptionFixture($tenant, ['entitlements' => ['limits' => ['integrations' => 2]]]);
    expect(fn () => app(TenantEntitlementService::class)->requireFeature($tenant->id, 'diagnostics.reanalyze'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(TenantEntitlementService::class)->requireLimit($tenant->id, 'integrations', 3))
        ->toThrow(AuthorizationException::class);
});

it('applies explicit role capability boundaries', function () {
    $tenant = Tenant::create(['name' => 'Role Workspace', 'slug' => 'role-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $operator = User::factory()->create();
    $viewer = User::factory()->create();
    $billing = User::factory()->create();
    $policy = app(TenantRoleAuthorization::class);

    $memberships = [];
    foreach ([$owner, $admin, $operator, $viewer, $billing] as $index => $user) {
        $memberships[] = $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => TenantMembership::ROLES[$index],
            'status' => TenantMembership::STATUS_ACTIVE,
            'accepted_at' => now(),
        ]);
    }

    expect($policy->allows($memberships[0], 'tenant.close'))->toBeTrue()
        ->and($policy->allows($memberships[1], 'tenant.close'))->toBeFalse()
        ->and($policy->allows($memberships[2], 'diagnostics.reanalyze'))->toBeTrue()
        ->and($policy->allows($memberships[3], 'diagnostics.reanalyze'))->toBeFalse()
        ->and($policy->allows($memberships[4], 'billing.read'))->toBeTrue()
        ->and($policy->allows($memberships[4], 'diagnostics.read'))->toBeFalse();
});

it('records manual and automated confirmed payments idempotently and updates invoice totals', function () {
    $tenant = Tenant::create(['name' => 'Billing Ledger Workspace', 'slug' => 'billing-ledger-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    $subscription = createTenantSubscriptionFixture($tenant);
    $invoice = TenantBillingInvoice::create([
        'tenant_id' => $tenant->id,
        'tenant_subscription_id' => $subscription->id,
        'invoice_number' => 'INV-TENANT-0001',
        'status' => 'open',
        'currency' => 'USD',
        'amount_due_minor' => 4900,
        'amount_paid_minor' => 0,
        'issued_at' => now(),
        'due_at' => now()->addDays(14),
    ]);
    $operator = User::factory()->create();
    $tenant->memberships()->create(['user_id' => $operator->id, 'role' => TenantMembership::ROLE_BILLING, 'status' => TenantMembership::STATUS_ACTIVE, 'accepted_at' => now()]);
    $service = app(TenantBillingLedgerService::class);
    $manual = [
        'collection_mode' => 'manual',
        'idempotency_key' => 'manual-bank-ref-001',
        'amount_minor' => 2000,
        'currency' => 'USD',
        'reference' => 'BANK-TRANSFER-001',
        'recorded_by_user_id' => $operator->id,
        'received_at' => now(),
    ];

    $first = $service->recordConfirmedPayment($tenant->id, $invoice->id, $manual);
    $again = $service->recordConfirmedPayment($tenant->id, $invoice->id, $manual);
    expect($again->id)->toBe($first->id)
        ->and($invoice->fresh()->amount_paid_minor)->toBe(2000)
        ->and($invoice->fresh()->status)->toBe('partially_paid');

    $service->recordConfirmedPayment($tenant->id, $invoice->id, [
        'collection_mode' => 'automated',
        'idempotency_key' => 'provider-event-002',
        'amount_minor' => 2900,
        'currency' => 'USD',
        'provider' => 'approved_gateway',
        'provider_payment_id' => 'PAY-002',
        'received_at' => now(),
    ]);

    expect($invoice->fresh()->amount_paid_minor)->toBe(4900)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->payments()->count())->toBe(2);
});

it('rejects cross-tenant invoice payment and overpayment without ledger mutation', function () {
    $tenantA = Tenant::create(['name' => 'Billing Tenant A', 'slug' => 'billing-tenant-a', 'status' => Tenant::STATUS_ACTIVE]);
    $tenantB = Tenant::create(['name' => 'Billing Tenant B', 'slug' => 'billing-tenant-b', 'status' => Tenant::STATUS_ACTIVE]);
    $subscription = createTenantSubscriptionFixture($tenantA);
    $invoice = TenantBillingInvoice::create([
        'tenant_id' => $tenantA->id,
        'tenant_subscription_id' => $subscription->id,
        'invoice_number' => 'INV-TENANT-0002',
        'status' => 'open',
        'currency' => 'USD',
        'amount_due_minor' => 1000,
        'amount_paid_minor' => 0,
        'issued_at' => now(),
    ]);
    $service = app(TenantBillingLedgerService::class);
    $payment = ['collection_mode' => 'automated', 'idempotency_key' => 'wrong-tenant', 'amount_minor' => 1001, 'currency' => 'USD', 'provider' => 'approved_gateway', 'provider_payment_id' => 'PAY-WRONG', 'received_at' => now()];

    expect(fn () => $service->recordConfirmedPayment($tenantB->id, $invoice->id, $payment))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => $service->recordConfirmedPayment($tenantA->id, $invoice->id, $payment))
        ->toThrow(RuntimeException::class, 'billing_invoice_payment_invariant_failed');

    expect($invoice->fresh()->amount_paid_minor)->toBe(0)
        ->and($invoice->payments()->count())->toBe(0);
});

<?php

use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Tenancy\TenantNotificationService;

function isolationTenant(string $slugPrefix): Tenant
{
    return Tenant::create([
        'name' => $slugPrefix,
        'slug' => $slugPrefix.'-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
}

function isolationOwner(Tenant $tenant, string $role = TenantMembership::ROLE_OWNER): User
{
    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => $role,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    return $user;
}

function isolationInvoice(Tenant $tenant): TenantBillingInvoice
{
    return TenantBillingInvoice::create([
        'tenant_id' => $tenant->id,
        'invoice_number' => 'RB-ISO-'.uniqid(),
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_minor' => 1000,
        'tax_minor' => 0,
        'amount_due_minor' => 1000,
        'amount_paid_minor' => 1000,
        'line_items' => [['description' => 'Isolation', 'amount_minor' => 1000]],
        'billing_details' => ['company_name' => $tenant->name],
        'issued_at' => now(),
        'paid_at' => now(),
    ]);
}

function isolationSubscription(Tenant $tenant): TenantSubscription
{
    return TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'starter',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 1000,
        'entitlements' => ['limits' => ['members' => 5]],
        'cancel_at_period_end' => false,
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);
}

it('returns 404 for cross-tenant invoice show and print', function () {
    $tenantA = isolationTenant('iso-inv-a');
    $tenantB = isolationTenant('iso-inv-b');
    $ownerA = isolationOwner($tenantA);
    $invoice = isolationInvoice($tenantA);

    $this->actingAs($ownerA)
        ->get(route('billing.invoices.show', [$tenantB, $invoice]))
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->get(route('billing.invoices.print', [$tenantB, $invoice]))
        ->assertNotFound();
});

it('forbids a foreign tenant owner from listing another tenant invoices or billing settings', function () {
    $tenantA = isolationTenant('iso-bill-a');
    $tenantB = isolationTenant('iso-bill-b');
    $ownerA = isolationOwner($tenantA);
    isolationInvoice($tenantB);

    $this->actingAs($ownerA)
        ->get(route('billing.invoices.index', $tenantB))
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->get(route('billing.settings.show', $tenantB))
        ->assertNotFound();
});

it('forbids cross-tenant subscription cancel and resume', function () {
    $tenantA = isolationTenant('iso-sub-a');
    $tenantB = isolationTenant('iso-sub-b');
    $ownerA = isolationOwner($tenantA);
    $subscriptionB = isolationSubscription($tenantB);

    $this->actingAs($ownerA)
        ->post(route('billing.subscriptions.cancel', [$tenantB, $subscriptionB]))
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->post(route('billing.subscriptions.resume', [$tenantB, $subscriptionB]))
        ->assertNotFound();

    expect($subscriptionB->fresh()->cancel_at_period_end)->toBeFalse();
});

it('returns 404 when cancelling a foreign subscription under the caller tenant path', function () {
    $tenantA = isolationTenant('iso-sub-mix-a');
    $tenantB = isolationTenant('iso-sub-mix-b');
    $ownerA = isolationOwner($tenantA);
    $subscriptionB = isolationSubscription($tenantB);

    $this->actingAs($ownerA)
        ->post(route('billing.subscriptions.cancel', [$tenantA, $subscriptionB]))
        ->assertNotFound();
});

it('keeps membership mutations scoped to the tenant path', function () {
    $tenantA = isolationTenant('iso-mem-a');
    $tenantB = isolationTenant('iso-mem-b');
    $ownerA = isolationOwner($tenantA);
    $ownerB = isolationOwner($tenantB);
    $membershipB = $tenantB->memberships()->where('user_id', $ownerB->id)->firstOrFail();

    $this->actingAs($ownerA)
        ->get(route('tenants.memberships.index', $tenantB))
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->patch(route('tenants.memberships.role', [$tenantA, $membershipB]), ['role' => TenantMembership::ROLE_VIEWER])
        ->assertNotFound();

    expect($membershipB->fresh()->role)->toBe(TenantMembership::ROLE_OWNER);
});

it('does not expose or mutate another tenant notification inbox', function () {
    $tenantA = isolationTenant('iso-note-a');
    $tenantB = isolationTenant('iso-note-b');
    $ownerA = isolationOwner($tenantA);
    $ownerB = isolationOwner($tenantB);

    $notification = app(TenantNotificationService::class)->publish(
        $tenantB->id,
        $ownerB->id,
        'diagnostics.run.completed',
        ['run_id' => 99, 'status' => 'completed'],
        'diagnostic-run:99:completed',
    );

    $this->actingAs($ownerA)
        ->get(route('tenants.notifications.index', $tenantB))
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->post(route('tenants.notifications.read', [$tenantA, $notification]))
        ->assertOk();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('forbids cross-tenant audit export', function () {
    $tenantA = isolationTenant('iso-audit-a');
    $tenantB = isolationTenant('iso-audit-b');
    $ownerA = isolationOwner($tenantA);

    $this->actingAs($ownerA)
        ->get(route('tenants.audit-events.export', $tenantB))
        ->assertNotFound();
});

it('forbids viewer role from managing billing subscriptions on their own tenant', function () {
    $tenant = isolationTenant('iso-viewer-bill');
    $viewer = isolationOwner($tenant, TenantMembership::ROLE_VIEWER);
    $subscription = isolationSubscription($tenant);

    $this->actingAs($viewer)
        ->post(route('billing.subscriptions.cancel', [$tenant, $subscription]))
        ->assertForbidden();
});

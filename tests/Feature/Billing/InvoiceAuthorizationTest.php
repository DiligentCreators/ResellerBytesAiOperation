<?php

use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantMembership;
use App\Models\User;

it('allows billing readers to view their tenant invoice and forbids cross-tenant access', function () {
    $tenantA = Tenant::create(['name' => 'A', 'slug' => 'inv-a-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
    $tenantB = Tenant::create(['name' => 'B', 'slug' => 'inv-b-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $owner->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $tenantA->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $invoice = TenantBillingInvoice::create([
        'tenant_id' => $tenantA->id,
        'invoice_number' => 'RB-2026-000099',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_minor' => 1000,
        'tax_minor' => 0,
        'amount_due_minor' => 1000,
        'amount_paid_minor' => 1000,
        'line_items' => [['description' => 'Test', 'amount_minor' => 1000]],
        'billing_details' => ['company_name' => 'A'],
        'issued_at' => now(),
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('billing.invoices.show', [$tenantA, $invoice]))
        ->assertOk();

    $this->actingAs($owner)
        ->get(route('billing.invoices.show', [$tenantB, $invoice]))
        ->assertNotFound();
});

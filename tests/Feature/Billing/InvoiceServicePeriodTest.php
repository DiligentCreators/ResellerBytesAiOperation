<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use Database\Seeders\PlanSeeder;

it('includes service period on invoice list detail and print props', function (): void {
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Invoice Period',
        'slug' => 'inv-period-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $plan = Plan::query()->where('code', 'starter')->firstOrFail();
    $checkout = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', [
        'company_name' => 'Period Co',
        'billing_email' => 'period@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);
    $invoice = $checkout['invoice']->fresh();

    expect($invoice->service_period_starts_at)->not->toBeNull()
        ->and($invoice->service_period_ends_at)->not->toBeNull();

    $this->actingAs($user)
        ->get(route('billing.invoices.index', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/invoices/index')
            ->has('invoices.data.0.service_period_starts_at')
            ->has('invoices.data.0.service_period_ends_at'));

    $this->actingAs($user)
        ->get(route('billing.invoices.show', [$tenant, $invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/invoices/show')
            ->where('invoice.service_period_starts_at', $invoice->service_period_starts_at->toIso8601String())
            ->where('invoice.service_period_ends_at', $invoice->service_period_ends_at->toIso8601String())
            ->has('invoice.line_items'));

    $this->actingAs($user)
        ->get(route('billing.invoices.print', [$tenant, $invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/invoices/print')
            ->has('invoice.service_period_starts_at')
            ->has('invoice.service_period_ends_at'));
});

it('falls back to a single line item when invoice lines are empty', function (): void {
    test()->seed(PlanSeeder::class);
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Invoice Fallback',
        'slug' => 'inv-fallback-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'country_code' => 'US',
    ]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $plan = Plan::query()->where('code', 'starter')->firstOrFail();
    $checkout = app(CheckoutService::class)->begin($tenant->id, $user, $plan, 'monthly', [
        'company_name' => 'Fallback Co',
        'billing_email' => 'fallback@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    $invoice = $checkout['invoice'];
    $invoice->forceFill(['line_items' => []])->save();

    $this->actingAs($user)
        ->get(route('billing.invoices.show', [$tenant, $invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invoice.line_items.0.amount_minor', $invoice->amount_due_minor)
            ->where('invoice.line_items.0.description', 'Invoice '.$invoice->invoice_number));
});

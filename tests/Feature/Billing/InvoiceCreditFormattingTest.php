<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\SubscriptionManagementService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('exposes charge subtotal before credits on detail and print for a prorated upgrade', function (): void {
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));

    $tenant = Tenant::create([
        'name' => 'Credit Format Tenant',
        'slug' => 'credit-fmt-'.uniqid(),
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

    $starter = Plan::query()->where('code', 'starter')->firstOrFail();
    $growth = Plan::query()->where('code', 'growth')->firstOrFail();

    $checkout = app(CheckoutService::class)->begin($tenant->id, $user, $starter, 'monthly', [
        'company_name' => 'Credit Co',
        'billing_email' => 'credit@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    app(SandboxPaymentGateway::class)->approve($checkout['invoice']);

    $subscription = $checkout['subscription']->fresh();
    $subscription->forceFill([
        'current_period_starts_at' => CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        'current_period_ends_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
    ])->save();

    $change = app(SubscriptionManagementService::class)->changePlan(
        $tenant->id,
        (int) $subscription->id,
        $user,
        $growth,
        'monthly',
        [
            'company_name' => 'Credit Co',
            'billing_email' => 'credit@example.test',
            'country' => 'US',
            'address_line1' => '1 Main',
        ],
    );

    $invoice = $change['invoice']->fresh();
    $newPrice = (int) $growth->price_monthly_minor;
    $creditMinor = (int) $invoice->line_items[1]['amount_minor'];
    $amountDue = $newPrice + $creditMinor;

    expect($invoice->subtotal_minor)->toBe($newPrice)
        ->and($invoice->amount_due_minor)->toBe($amountDue)
        ->and($invoice->line_items)->toHaveCount(2)
        ->and($creditMinor)->toBeLessThan(0);

    // Print / PDF presentation uses the shared money formatter (sample amounts).
    $sampleCredit = -1 * fake()->numberBetween(1000, 9000);
    $samplePrice = fake()->numberBetween(10_000, 20_000);
    expect(Money::format($sampleCredit))->toStartWith("\u{2212}\$")
        ->and(Money::format($samplePrice))->toStartWith('$');

    $assertTotals = function ($page) use ($newPrice, $creditMinor, $amountDue) {
        return $page
            ->where('invoice.subtotal_minor', $newPrice)
            ->where('invoice.tax_minor', 0)
            ->where('invoice.amount_due_minor', $amountDue)
            ->where('invoice.credit_lines.0.amount_minor', $creditMinor)
            ->where('invoice.line_items.0.amount_minor', $newPrice)
            ->where('invoice.line_items.1.amount_minor', $creditMinor)
            ->has('invoice.credit_lines.0.description');
    };

    $this->actingAs($user)
        ->get(route('billing.invoices.show', [$tenant, $invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $assertTotals(
            $page->component('billing/invoices/show'),
        ));

    $this->actingAs($user)
        ->get(route('billing.invoices.print', [$tenant, $invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $assertTotals(
            $page->component('billing/invoices/print'),
        ));
});

it('keeps plain invoice subtotal as total minus tax without credit lines', function (): void {
    test()->seed(PlanSeeder::class);
    priceSeededCatalog();
    config()->set('billing.default_gateway', 'sandbox');

    $tenant = Tenant::create([
        'name' => 'Plain Invoice Tenant',
        'slug' => 'plain-inv-'.uniqid(),
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
        'company_name' => 'Plain Co',
        'billing_email' => 'plain@example.test',
        'country' => 'US',
        'address_line1' => '1 Main',
    ]);
    $invoice = $checkout['invoice']->fresh();

    $expectedSubtotal = $invoice->amount_due_minor - $invoice->tax_minor;

    $this->actingAs($user)
        ->get(route('billing.invoices.show', [$tenant, $invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/invoices/show')
            ->where('invoice.subtotal_minor', $expectedSubtotal)
            ->where('invoice.amount_due_minor', $invoice->amount_due_minor)
            ->where('invoice.credit_lines', []));

    $this->actingAs($user)
        ->get(route('billing.invoices.print', [$tenant, $invoice]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('billing/invoices/print')
            ->where('invoice.subtotal_minor', $expectedSubtotal)
            ->where('invoice.credit_lines', []));
});

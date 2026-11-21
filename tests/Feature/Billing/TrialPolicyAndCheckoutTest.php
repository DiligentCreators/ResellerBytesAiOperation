<?php

use App\Models\BillingGatewaySetting;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBillingInvoice;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\TrialPolicy;
use App\Services\Billing\TrialService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'billing.default_gateway' => 'sandbox',
        'billing.kelviq.mode' => 'sandbox',
        'billing.kelviq.api_key' => 'kq_test_api_key_long',
        'billing.kelviq.webhook_secret' => 'kq_whsec_test_secret',
        'billing.kelviq.sandbox_base_url' => 'https://sandboxapi.kelviq.com/api/v1',
        'billing.kelviq.live_base_url' => 'https://api.kelviq.com/api/v1',
    ]);
});

function trialFixture(array $planOverrides = []): array
{
    $tenant = Tenant::create([
        'name' => 'Trial Tenant',
        'slug' => 'trial-'.uniqid(),
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
    $plan = Plan::factory()->create(array_merge([
        'trial_enabled' => true,
        'trial_days' => 14,
        'is_active' => true,
        'is_public' => true,
        'is_contact_sales' => false,
        'monthly_active' => true,
        'yearly_active' => true,
        'price_monthly_minor' => 4900,
        'price_yearly_minor' => 49000,
        'kelviq_plan_identifier_monthly' => 'kq_starter_m',
        'kelviq_plan_identifier_yearly' => 'kq_starter_y',
    ], $planOverrides));

    return [$tenant, $user, $plan];
}

function enableTrials(bool $cardRequired = false, bool $firstTimeOnly = true): void
{
    BillingGatewaySetting::query()->updateOrCreate(['id' => 1], [
        'gateway' => 'sandbox',
        'kelviq_mode' => 'sandbox',
        'trials_enabled' => true,
        'trial_card_required' => $cardRequired,
        'trial_first_time_only' => $firstTimeOnly,
    ]);
}

function trialDetails(): array
{
    return [
        'company_name' => 'Trial Co',
        'billing_email' => 'trial@example.test',
        'country' => 'US',
        'address_line1' => '1 Trial St',
    ];
}

it('applies the TrialPolicy eligibility matrix', function () {
    [$tenant, $user, $plan] = trialFixture();

    expect(app(TrialPolicy::class)->trialDaysFor($tenant, $plan))->toBe(0);

    enableTrials();
    expect(app(TrialPolicy::class)->trialDaysFor($tenant, $plan->fresh()))->toBe(14);

    $plan->forceFill(['trial_enabled' => false])->save();
    expect(app(TrialPolicy::class)->trialDaysFor($tenant, $plan->fresh()))->toBe(0);

    $plan->forceFill(['trial_enabled' => true, 'trial_days' => 0])->save();
    expect(app(TrialPolicy::class)->trialDaysFor($tenant, $plan->fresh()))->toBe(0);

    $plan->forceFill(['trial_days' => 14])->save();
    TenantSubscription::query()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'plan_code' => $plan->code,
        'billing_mode' => 'automated',
        'status' => 'canceled',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'charge_period' => 'monthly',
        'entitlements' => $plan->entitlements,
        'cancel_at_period_end' => false,
        'current_period_starts_at' => CarbonImmutable::now('UTC')->subMonth(),
        'current_period_ends_at' => CarbonImmutable::now('UTC')->subDay(),
        'canceled_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);

    expect(app(TrialPolicy::class)->trialDaysFor($tenant, $plan->fresh()))->toBe(0);

    BillingGatewaySetting::query()->findOrFail(1)->forceFill(['trial_first_time_only' => false])->save();
    expect(app(TrialPolicy::class)->trialDaysFor($tenant, $plan->fresh()))->toBe(14);
});

it('RB-2: paid checkout never applies trial days or sends trialPeriod to Kelviq', function () {
    [$tenant, $user, $plan] = trialFixture(['trial_days' => 14, 'trial_enabled' => true]);
    enableTrials(cardRequired: true);

    config()->set('billing.default_gateway', 'kelviq');
    $settings = BillingGatewaySetting::query()->findOrFail(1);
    $settings->forceFill([
        'gateway' => 'kelviq',
        'kelviq_sandbox_api_key' => 'kq_test_api_key_long',
        'kelviq_sandbox_webhook_secret' => 'kq_whsec_test_secret',
    ])->save();

    Http::preventStrayRequests();
    Http::fake([
        'https://sandboxapi.kelviq.com/api/v1/customers/*' => Http::response(['customerId' => 'acct'], 201),
        'https://sandboxapi.kelviq.com/api/v1/checkout/*' => Http::response([
            'checkoutUrl' => 'https://www.kelviq.com/checkout/cs_paid/',
            'checkoutSessionId' => 'cs_paid',
        ], 201),
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));

    $result = app(CheckoutService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        trialDetails(),
    );

    expect($result['subscription']->status)->toBe('incomplete')
        ->and($result['subscription']->current_period_ends_at->equalTo(
            CarbonImmutable::parse('2026-12-20 12:00:00', 'UTC'),
        ))->toBeTrue()
        ->and($result['invoice']->amount_due_minor)->toBe(4900)
        ->and($result['subscription']->trial_ends_at)->toBeNull();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/checkout/')) {
            return false;
        }

        $data = $request->data();

        return ! array_key_exists('trialPeriod', $data)
            && ($data['metadata']['checkout_kind'] ?? null) === 'checkout';
    });
});

it('starts a no-card trial without invoice or gateway call', function () {
    [$tenant, $user, $plan] = trialFixture();
    enableTrials(cardRequired: false);

    Http::fake();

    $result = app(TrialService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
    );

    expect($result['card_required'])->toBeFalse()
        ->and($result['invoice'])->toBeNull()
        ->and($result['checkout_url'])->toBeNull()
        ->and($result['subscription']->status)->toBe('trialing')
        ->and($result['subscription']->unit_amount_minor)->toBe(4900)
        ->and($result['subscription']->charge_period)->toBe('monthly')
        ->and($result['subscription']->trial_ends_at)->not->toBeNull()
        ->and(TenantBillingInvoice::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('sends trialPeriod to Kelviq when a card is required for trial', function () {
    [$tenant, $user, $plan] = trialFixture(['trial_days' => 14]);
    enableTrials(cardRequired: true);
    config()->set('billing.default_gateway', 'kelviq');
    $settings = BillingGatewaySetting::query()->findOrFail(1);
    $settings->forceFill([
        'gateway' => 'kelviq',
        'kelviq_sandbox_api_key' => 'kq_test_api_key_long',
        'kelviq_sandbox_webhook_secret' => 'kq_whsec_test_secret',
    ])->save();

    Http::preventStrayRequests();
    Http::fake([
        'https://sandboxapi.kelviq.com/api/v1/customers/*' => Http::response(['customerId' => 'acct'], 201),
        'https://sandboxapi.kelviq.com/api/v1/checkout/*' => Http::response([
            'checkoutUrl' => 'https://www.kelviq.com/checkout/cs_trial/',
            'checkoutSessionId' => 'cs_trial',
        ], 201),
    ]);

    $result = app(TrialService::class)->begin(
        $tenant->id,
        $user,
        $plan,
        'monthly',
        trialDetails(),
    );

    expect($result['card_required'])->toBeTrue()
        ->and($result['subscription']->status)->toBe('trialing')
        ->and($result['invoice']->amount_due_minor)->toBe(0)
        ->and($result['checkout_url'])->toBe('https://www.kelviq.com/checkout/cs_trial/');

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/checkout/')) {
            return false;
        }

        $data = $request->data();

        return ($data['trialPeriod'] ?? null) === 14
            && ($data['metadata']['checkout_kind'] ?? null) === 'trial';
    });
});

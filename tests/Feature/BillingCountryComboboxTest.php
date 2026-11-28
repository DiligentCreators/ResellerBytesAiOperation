<?php

use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

it('saves the billing country as the same ISO code the combobox submits', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Country Combobox Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $this->actingAs($user)
        ->get(route('billing.settings.show', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/settings')
            ->has('countries')
            ->where('countries.0.code', fn ($code) => is_string($code) && strlen($code) === 2));

    $this->actingAs($user)
        ->put(route('billing.settings.details', $tenant), [
            'company_name' => 'Country Combobox Co',
            'billing_email' => 'billing@example.test',
            'country' => 'GB',
            'address_line1' => '1 Test Street',
        ])
        ->assertRedirect();

    $tenant->refresh();

    expect(data_get($tenant->settings, 'billing.country'))->toBe('GB');
});

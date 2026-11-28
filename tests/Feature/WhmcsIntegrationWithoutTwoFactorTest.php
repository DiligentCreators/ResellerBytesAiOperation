<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Whmcs\WhmcsIntegrationProvisioner;

it('lets a verified owner without two-factor create an integration via the web form', function () {
    $owner = User::factory()->create([
        'email_verified_at' => now(),
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);
    $tenant = Tenant::create([
        'name' => 'No 2FA Workspace',
        'slug' => 'no-2fa-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'no-2fa-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['limits' => ['integrations' => 5]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $this->actingAs($owner)
        ->post('/integrations', [
            'name' => 'WHMCS Without 2FA',
            'tenant_id' => $tenant->id,
        ])
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHas('integration_credentials');

    expect(WhmcsIntegration::query()->where('name', 'WHMCS Without 2FA')->exists())->toBeTrue();
});

it('lets the provisioner create an integration for a verified owner without two-factor', function () {
    $owner = User::factory()->create([
        'email' => 'no-2fa-owner@example.test',
        'email_verified_at' => now(),
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    $result = app(WhmcsIntegrationProvisioner::class)->create($owner, 'CLI Without 2FA');

    expect($result['integration']->name)->toBe('CLI Without 2FA')
        ->and($result['plainTextSecret'])->toMatch('/\A[a-f0-9]{64}\z/');
});

it('still requires a verified email to create an integration', function () {
    $owner = User::factory()->create([
        'email' => 'unverified-owner@example.test',
        'email_verified_at' => null,
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    expect(fn () => app(WhmcsIntegrationProvisioner::class)->create($owner, 'Blocked Unverified'))
        ->toThrow(InvalidArgumentException::class, 'verified email');

    $this->artisan('whmcs:integration:create', [
        'name' => 'Blocked Unverified CLI',
        '--owner' => $owner->email,
    ])->assertFailed();

    expect(WhmcsIntegration::query()->count())->toBe(0);
});

it('creates an integration via artisan for a verified owner without two-factor', function () {
    $owner = User::factory()->create([
        'email' => 'artisan-no-2fa@example.test',
        'email_verified_at' => now(),
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    $this->artisan('whmcs:integration:create', [
        'name' => 'Artisan Without 2FA',
        '--owner' => $owner->email,
    ])->assertSuccessful();

    expect(WhmcsIntegration::query()->where('name', 'Artisan Without 2FA')->exists())->toBeTrue();
});

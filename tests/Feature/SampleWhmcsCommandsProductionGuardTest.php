<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;

it('refuses whmcs:sample-integration when APP_ENV is production', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Prod Guard Workspace',
        'slug' => 'prod-guard-'.uniqid(),
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
        'plan_code' => 'prod-guard-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => fake()->numberBetween(1000, 9000),
        'entitlements' => ['limits' => ['integrations' => 5, 'diagnostics_per_period' => 100]],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('whmcs:sample-integration', ['tenant' => $tenant->id])
            ->expectsOutputToContain('refuses to run in production')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn (): string => $previous);
    }

    expect(WhmcsIntegration::query()->count())->toBe(0);
});

it('refuses whmcs:sample-snapshot when APP_ENV is production', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'Prod guard install',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('whmcs:sample-snapshot', [
            'integration' => $integration->id,
            '--fixture' => 'schema_1_1_positive',
        ])
            ->expectsOutputToContain('refuses to run in production')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn (): string => $previous);
    }

    expect($integration->fresh()->diagnosticRuns()->count())->toBe(0);
});

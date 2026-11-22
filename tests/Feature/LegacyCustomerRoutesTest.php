<?php

use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

it('keeps legacy customer urls working after the navigation reshape', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Legacy Routes Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $this->actingAs($user);

    $paths = [
        '/dashboard',
        '/fix-center',
        '/reports',
        '/reports/trends',
        '/integrations',
        '/diagnostic-runs',
        '/findings',
        '/safe-actions',
        '/whmcs-addon/setup',
        '/billing/plans',
        "/tenants/{$tenant->id}/settings",
        "/tenants/{$tenant->id}/memberships",
        "/tenants/{$tenant->id}/alerts",
        "/tenants/{$tenant->id}/auto-fix",
        "/tenants/{$tenant->id}/billing",
        "/tenants/{$tenant->id}/billing/invoices",
        "/tenants/{$tenant->id}/notifications",
        '/onboarding',
    ];

    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }
});

<?php

use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated self-serve customers without two-factor can visit the dashboard', function () {
    $user = User::factory()->create();
    app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Dash Workspace',
        'country_code' => 'US',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('authenticated users with confirmed two-factor authentication can visit the dashboard', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('platform operators without two-factor are redirected to security settings', function () {
    config(['operations.owner_email' => 'dash-owner@example.test']);
    $user = User::factory()->create(['email' => 'dash-owner@example.test']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

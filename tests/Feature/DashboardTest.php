<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users without confirmed two-factor authentication are redirected to security settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('authenticated users with confirmed two-factor authentication can visit the dashboard', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

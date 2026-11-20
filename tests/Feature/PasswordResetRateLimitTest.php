<?php

use Illuminate\Support\Facades\Route;

it('registers password reset and verification routes for throttling', function () {
    expect(Route::getRoutes()->getByName('password.email'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('password.update'))->not->toBeNull()
        ->and(Route::getRoutes()->getByName('verification.send'))->not->toBeNull();
});

it('throttles repeated password reset posts from the same IP', function () {
    $this->travelTo(now()->addDay());

    for ($i = 0; $i < 20; $i++) {
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => "reset-{$i}@example.test"])
            ->assertRedirect();
    }

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'reset-overflow@example.test'])
        ->assertStatus(429);
});

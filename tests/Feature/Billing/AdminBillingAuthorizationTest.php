<?php

use App\Models\CentralStaffAccess;
use App\Models\User;
use Database\Seeders\PlanSeeder;

it('forbids platform billing views without the billing permission', function () {
    $granter = User::factory()->create(['email_verified_at' => now()]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    CentralStaffAccess::query()->create([
        'user_id' => $user->id,
        'permissions' => ['support'],
        'granted_by_user_id' => $granter->id,
    ]);

    $this->actingAs($user)
        ->get(route('platform.billing.index'))
        ->assertForbidden();
});

it('allows platform billing views with the billing permission', function () {
    $this->seed(PlanSeeder::class);
    $granter = User::factory()->create(['email_verified_at' => now()]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    CentralStaffAccess::query()->create([
        'user_id' => $user->id,
        'permissions' => ['billing'],
        'granted_by_user_id' => $granter->id,
    ]);

    $this->actingAs($user)
        ->get(route('platform.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('platform/billing/index'));

    $this->actingAs($user)
        ->get(route('platform.billing.plans.index'))
        ->assertOk();
});

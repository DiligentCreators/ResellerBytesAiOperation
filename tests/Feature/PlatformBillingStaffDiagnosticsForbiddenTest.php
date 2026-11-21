<?php

use App\Models\CentralStaffAccess;
use App\Models\User;

it('forbids platform billing-only staff from browsing diagnostic-runs and findings indexes', function () {
    $granter = User::factory()->create(['email_verified_at' => now()]);
    $billingStaff = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    CentralStaffAccess::query()->create([
        'user_id' => $billingStaff->id,
        'permissions' => ['billing'],
        'granted_by_user_id' => $granter->id,
    ]);

    $this->actingAs($billingStaff)
        ->get('/diagnostic-runs')
        ->assertForbidden();

    $this->actingAs($billingStaff)
        ->get('/findings')
        ->assertForbidden();
});

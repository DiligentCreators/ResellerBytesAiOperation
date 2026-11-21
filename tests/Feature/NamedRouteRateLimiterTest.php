<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('does not share throttle buckets across unrelated named route limiters', function () {
    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Throttle Isolation',
        'slug' => 'throttle-iso-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    RateLimiter::clear('tenants-audit-events-export');
    RateLimiter::clear('tenants-notifications-index');

    // Exhaust the audit-export limiter (3/min) without touching notifications.
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user)
            ->get(route('tenants.audit-events.export', $tenant))
            ->assertOk();
    }

    $this->actingAs($user)
        ->get(route('tenants.audit-events.export', $tenant))
        ->assertTooManyRequests();

    // A different named limiter for the same user must still succeed.
    $this->actingAs($user)
        ->get(route('tenants.notifications.index', $tenant))
        ->assertOk();
});

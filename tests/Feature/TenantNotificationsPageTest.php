<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantNotificationService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: Tenant, 1: User}
 */
function notificationsPageFixture(string $slugPrefix = 'notifications-page'): array
{
    $tenant = Tenant::create([
        'name' => 'Notifications Page',
        'slug' => $slugPrefix.'-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'timezone' => 'America/New_York',
        'settings' => ['branding' => ['display_name' => 'Notifications Page']],
    ]);
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    return [$tenant, $owner];
}

it('renders the notifications inertia page with presented notification props', function () {
    [$tenant, $owner] = notificationsPageFixture('notifications-with-items');

    app(TenantNotificationService::class)->publish(
        $tenant->id,
        $owner->id,
        'diagnostics.run.completed',
        ['run_id' => 7, 'status' => 'completed', 'message' => 'Scan finished'],
        'diagnostic-run:7:completed',
    );

    $this->actingAs($owner)
        ->get(route('tenants.notifications.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/notifications')
            ->where('tenant.id', $tenant->id)
            ->where('tenant.name', $tenant->name)
            ->where('tenant.timezone', 'America/New_York')
            ->has('notifications.data', 1)
            ->has('notifications.links')
            ->where('notifications.data.0.event_type', 'diagnostics.run.completed')
            ->where('notifications.data.0.type', 'diagnostics.run.completed')
            ->where('notifications.data.0.title', 'diagnostics · run · completed')
            ->where('notifications.data.0.message', 'Scan finished')
            ->where('notifications.data.0.payload.run_id', 7)
            ->where('notifications.data.0.read_at', null)
            ->has('notifications.data.0.created_at')
            ->has('notifications.data.0.id'));
});

it('renders an empty notifications inertia page for a member with no alerts', function () {
    [$tenant, $owner] = notificationsPageFixture('notifications-empty');

    $this->actingAs($owner)
        ->get(route('tenants.notifications.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/notifications')
            ->where('tenant.id', $tenant->id)
            ->has('notifications.data', 0)
            ->has('notifications.links'));
});

it('hides notifications from non-members with 404', function () {
    [$tenant] = notificationsPageFixture('notifications-outsider-tenant');
    $outsider = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);

    $this->actingAs($outsider)
        ->get(route('tenants.notifications.index', $tenant))
        ->assertNotFound();
});

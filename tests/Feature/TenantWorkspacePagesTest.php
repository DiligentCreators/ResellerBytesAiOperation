<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantNotificationService;
use Inertia\Testing\AssertableInertia as Assert;

function workspacePageFixture(): array
{
    $tenant = Tenant::create([
        'name' => 'Workspace Pages',
        'slug' => 'workspace-pages-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'settings' => ['branding' => ['display_name' => 'Workspace Pages']],
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

it('renders tenant settings memberships and notifications as Inertia pages', function () {
    [$tenant, $owner] = workspacePageFixture();
    app(TenantNotificationService::class)->publish(
        $tenant->id,
        $owner->id,
        'diagnostics.run.completed',
        ['run_id' => 1, 'status' => 'completed'],
        'diagnostic-run:1:completed',
    );

    $this->actingAs($owner)
        ->get(route('tenants.settings.show', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/settings')
            ->where('tenant.id', $tenant->id)
            ->where('canManage', true));

    $this->actingAs($owner)
        ->get(route('tenants.memberships.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/memberships')
            ->has('memberships.data', 1));

    $this->actingAs($owner)
        ->get(route('tenants.notifications.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/notifications')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.event_type', 'diagnostics.run.completed')
            ->has('notifications.data.0.title')
            ->has('notifications.data.0.message'));
});

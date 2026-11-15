<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantBrandingSettings;
use Illuminate\Auth\Access\AuthorizationException;

it('allows owner and admin to update only the tenant branding settings with audit evidence', function () {
    $tenant = Tenant::create([
        'name' => 'Branding Workspace',
        'slug' => 'branding-workspace',
        'status' => Tenant::STATUS_ACTIVE,
        'settings' => ['locale' => 'en', 'branding' => ['display_name' => 'Old Name']],
    ]);
    $admin = User::factory()->create();
    $tenant->memberships()->create([
        'user_id' => $admin->id,
        'role' => TenantMembership::ROLE_ADMIN,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $updated = app(TenantBrandingSettings::class)->update($admin, $tenant->id, [
        'display_name' => 'New Brand',
        'support_email' => 'support@example.test',
        'primary_color' => '#123ABC',
        'logo_url' => 'https://assets.example.test/logo.svg',
    ], '192.0.2.20');

    expect($updated->settings['locale'])->toBe('en')
        ->and($updated->settings['branding']['display_name'])->toBe('New Brand')
        ->and($updated->settings['branding']['support_email'])->toBe('support@example.test')
        ->and(\Illuminate\Support\Facades\DB::table('audit_events')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'tenant.branding.updated')
            ->exists())->toBeTrue();
});

it('denies tenant branding writes to non-admin members', function () {
    $tenant = Tenant::create([
        'name' => 'Viewer Branding Workspace',
        'slug' => 'viewer-branding-workspace',
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $viewer = User::factory()->create();
    $tenant->memberships()->create([
        'user_id' => $viewer->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    expect(fn () => app(TenantBrandingSettings::class)->update($viewer, $tenant->id, [
        'display_name' => 'Unauthorized Brand',
    ]))->toThrow(AuthorizationException::class);

    expect($tenant->fresh()->settings)->toBeNull();
});

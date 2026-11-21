<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('does not let billing-only members read integrations, diagnostics, findings, or dashboard stats', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Billing Role Workspace',
        'slug' => 'billing-role-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Billing-hidden integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenant->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('b', 64),
    ]);
    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'billing-role-finding'),
        'category' => 'security',
        'severity' => 'critical',
        'title' => 'Hidden from billing',
        'summary' => 'Billing role must not see this.',
        'evidence' => ['source' => 'test'],
        'status' => 'open',
    ]);

    $billing = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $billing->id,
        'role' => TenantMembership::ROLE_BILLING,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($billing);

    $this->get('/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/index')
            ->has('integrations.data', 0));

    $this->get('/diagnostic-runs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('diagnostic-runs/index')
            ->has('runs.data', 0));

    $this->get('/findings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/index')
            ->has('findings.data', 0));

    $this->get('/diagnostic-runs/'.$run->id)->assertNotFound();
    $this->get('/findings/'.$finding->id)->assertNotFound();

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('stats.active_integrations', 0)
            ->where('stats.total_runs', 0)
            ->where('stats.open_findings', 0)
            ->where('stats.critical_findings', 0));
});

it('returns 404 when rotating another tenant integration', function () {
    $ownerA = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $ownerB = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenantB = Tenant::create([
        'name' => 'Foreign Rotate Workspace',
        'slug' => 'foreign-rotate-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
    ]);
    $tenantB->memberships()->create([
        'user_id' => $ownerB->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $ownerB->id,
        'tenant_id' => $tenantB->id,
        'name' => 'Foreign integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $this->actingAs($ownerA)
        ->post(route('integrations.rotate', $integration))
        ->assertNotFound();
});

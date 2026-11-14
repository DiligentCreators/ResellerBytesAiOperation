<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\User;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createForeignTenantDiagnosticRecords(User $owner): array
{
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'name' => 'Private tenant integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('a', 64),
    ]);

    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'fingerprint' => hash('sha256', 'foreign-tenant-finding'),
        'category' => 'security',
        'severity' => 'critical',
        'title' => 'Private tenant finding',
        'summary' => 'Must not be visible to another tenant.',
        'evidence' => ['source' => 'tenant-private'],
        'status' => 'open',
    ]);

    return [$integration, $run, $finding];
}

it('does not expose another owner integrations, diagnostics, findings, or aggregate counts', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    [$integration] = createForeignTenantDiagnosticRecords($owner);

    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $this->actingAs($viewer);

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

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('stats.active_integrations', 0)
            ->where('stats.total_runs', 0)
            ->where('stats.open_findings', 0)
            ->where('stats.critical_findings', 0));

    expect($integration->owner_user_id)->toBe($owner->id);
});

it('returns not found for cross-owner diagnostic and finding detail access', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    [, $run, $finding] = createForeignTenantDiagnosticRecords($owner);

    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $this->actingAs($viewer);

    $this->get('/diagnostic-runs/'.$run->id)->assertNotFound();
    $this->get('/findings/'.$finding->id)->assertNotFound();
});

it('does not allow a different owner to trigger reanalysis', function () {
    config(['services.openai.key' => 'test-api-key']);
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    [, $run] = createForeignTenantDiagnosticRecords($owner);

    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $this->actingAs($viewer)
        ->post('/diagnostic-runs/'.$run->id.'/reanalyze')
        ->assertNotFound();

    expect($run->fresh()->status)->toBe('received');
});

<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantAlertChannel;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

/**
 * @return array{0: User, 1: Tenant}
 */
function nextStepOwnerFixture(): array
{
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Next Step Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $tenant->forceFill(['onboarding_completed_at' => now()])->save();

    return [$user, $tenant];
}

it('shows connect WHMCS as the next step when there is no integration', function () {
    [$user] = nextStepOwnerFixture();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('nextStep.key', 'connect_whmcs')
            ->where('nextStep.cta_href', '/integrations')
            ->where('nextStep.cta_label', 'Connect WHMCS'));
});

it('shows waiting for heartbeat when a connection exists but has never checked in', function () {
    [$user, $tenant] = nextStepOwnerFixture();

    WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $user->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextStep.key', 'waiting_heartbeat')
            ->where('nextStep.cta_href', '/whmcs-addon/setup'));
});

it('shows fix critical issues linking to the top critical finding', function () {
    [$user, $tenant] = nextStepOwnerFixture();

    $integration = WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $user->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => now(),
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
    ]);

    $run = DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.4',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => [],
        'payload_sha256' => hash('sha256', 'next-step-critical'),
    ]);

    $finding = DiagnosticFinding::query()->create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'next-step-critical'),
        'issue_key' => 'storage.disk_low',
        'source' => 'rule',
        'affected_count' => 1,
        'fix_mode' => 'guided',
        'category' => 'host',
        'severity' => 'critical',
        'title' => 'Server disk free space is low',
        'summary' => 'test',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextStep.key', 'fix_critical')
            ->where('nextStep.cta_href', '/findings/'.$finding->id)
            ->where('nextStep.description', 'Server disk free space is low'));
});

it('names the same top critical issue on the next-step card and health why-line', function () {
    [$user, $tenant] = nextStepOwnerFixture();

    $integration = WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $user->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => now(),
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
    ]);

    $run = DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.4',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => [],
        'payload_sha256' => hash('sha256', 'next-step-shared-rank'),
    ]);

    // Newer + lower catalog priority if ordered by created_at alone.
    DiagnosticFinding::query()->create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'php-eol-newer'),
        'issue_key' => 'php.version_eol',
        'source' => 'rule',
        'affected_count' => 1,
        'fix_mode' => 'guided',
        'category' => 'php',
        'severity' => 'critical',
        'title' => 'Web runtime uses an end-of-life PHP version',
        'summary' => 'newer critical',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
        'created_at' => now(),
    ]);

    // Older + higher catalog priority (cron.not_running = 1).
    DiagnosticFinding::query()->create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'cron-not-running-older'),
        'issue_key' => 'cron.not_running',
        'source' => 'rule',
        'affected_count' => 1,
        'fix_mode' => 'guided',
        'category' => 'cron',
        'severity' => 'critical',
        'title' => 'WHMCS system cron is not running',
        'summary' => 'older critical',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
        'created_at' => now()->subHour(),
    ]);

    $expectedTitle = 'WHMCS system cron is not running';

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextStep.key', 'fix_critical')
            ->where('nextStep.description', $expectedTitle)
            ->where('health.top_open_finding.title', $expectedTitle));
});

it('shows all clear when connected checked and no critical issues with alerts configured', function () {
    [$user, $tenant] = nextStepOwnerFixture();

    $integration = WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $user->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'last_heartbeat_at' => now(),
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
    ]);

    DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.4',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => [],
        'payload_sha256' => hash('sha256', 'next-step-clear'),
    ]);

    TenantAlertChannel::query()->create([
        'tenant_id' => $tenant->id,
        'type' => TenantAlertChannel::TYPE_EMAIL,
        'name' => 'Ops',
        'target' => 'ops@example.test',
        'events' => ['installation.heartbeat.down'],
        'enabled' => true,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextStep.key', 'all_clear')
            ->where('nextStep.cta_href', '/reports'));
});

it('shows finish onboarding before other next steps when setup is incomplete', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Incomplete Next Step Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextStep.key', 'finish_onboarding')
            ->where('nextStep.cta_href', '/onboarding'));
});

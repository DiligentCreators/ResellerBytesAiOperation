<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\TenantAlertChannel;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Tenancy\GettingStartedChecklist;
use App\Services\Tenancy\TenantProvisioningService;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

function checklistOwnerFixture(): array
{
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $tenant = app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Checklist Co',
        'country_code' => 'GB',
        'timezone' => 'UTC',
    ]);

    $tenant->forceFill(['onboarding_completed_at' => now()])->save();

    return [$user, $tenant];
}

it('hides the getting started checklist until onboarding is complete', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);
    app(TenantProvisioningService::class)->provisionOwnerWorkspace($user, [
        'name' => 'Incomplete Co',
        'country_code' => 'US',
        'timezone' => 'UTC',
    ]);

    expect(app(GettingStartedChecklist::class)->forOwner($user))->toBeNull();
});

it('derives checklist completion from alert channels fixes and invites', function () {
    [$owner, $tenant] = checklistOwnerFixture();

    $checklist = app(GettingStartedChecklist::class);

    expect($checklist->deriveItems($tenant))->toBe([
        'alert_channel' => false,
        'first_fix' => false,
        'invite_teammate' => false,
    ]);

    TenantAlertChannel::query()->create([
        'tenant_id' => $tenant->id,
        'type' => TenantAlertChannel::TYPE_EMAIL,
        'name' => 'Ops',
        'target' => 'ops@example.test',
        'events' => ['installation.heartbeat.down'],
        'enabled' => true,
    ]);

    $integration = WhmcsIntegration::query()->create([
        'tenant_id' => $tenant->id,
        'owner_user_id' => $owner->id,
        'name' => 'Primary',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::query()->create([
        'tenant_id' => $tenant->id,
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) str()->uuid(),
        'schema_version' => '1',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.0']],
        'payload_sha256' => hash('sha256', 'checklist'),
    ]);

    DiagnosticFinding::query()->create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'fp-checklist'),
        'source' => 'rule',
        'category' => 'cron',
        'severity' => 'warning',
        'title' => 'Cron late',
        'summary' => 'Cron is late.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now(),
    ]);

    $invitee = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $invitee->id,
        'role' => TenantMembership::ROLE_OPERATOR,
        'status' => TenantMembership::STATUS_INVITED,
        'invited_by_user_id' => $owner->id,
    ]);

    expect($checklist->deriveItems($tenant->fresh()))->toBe([
        'alert_channel' => true,
        'first_fix' => true,
        'invite_teammate' => true,
    ]);

    $state = $checklist->forOwner($owner);
    expect($state['visible'])->toBeFalse()
        ->and($state['completed'])->toBeTrue();
});

it('can dismiss the getting started checklist from the dashboard', function () {
    [$owner, $tenant] = checklistOwnerFixture();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('gettingStarted.visible', true));

    $this->actingAs($owner)
        ->post(route('onboarding.getting-started.dismiss'))
        ->assertRedirect(route('dashboard'));

    expect(data_get($tenant->fresh()->settings, 'getting_started.dismissed_at'))->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('gettingStarted.visible', false)
            ->where('gettingStarted.dismissed', true));
});

<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Audit\AuditEventWriter;
use App\Services\Tenancy\TenantNotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createForeignTenantDiagnosticRecords(User $owner, ?string $tenantId = null): array
{
    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenantId,
        'name' => 'Private tenant integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $tenantId,
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
        'tenant_id' => $tenantId,
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

it('serves tenant-assigned integrations and nested diagnostics only to active tenant members', function () {
    $historicalOwner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Controller Access Workspace',
        'slug' => 'controller-access-workspace',
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $historicalOwner->id,
    ]);
    [$integration, $run, $finding] = createForeignTenantDiagnosticRecords($historicalOwner, $tenant->id);

    $member = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create([
        'user_id' => $member->id,
        'role' => TenantMembership::ROLE_VIEWER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $this->actingAs($member);
    $this->get('/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('integrations/index')->has('integrations.data', 1));
    $this->get('/diagnostic-runs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('diagnostic-runs/index')->has('runs.data', 1));
    $this->get('/diagnostic-runs/'.$run->id)->assertOk();
    $this->get('/findings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('findings/index')->has('findings.data', 1));
    $this->get('/findings/'.$finding->id)->assertOk();
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('stats.active_integrations', 1)
        ->where('stats.total_runs', 1)
        ->where('stats.open_findings', 1)
        ->where('stats.critical_findings', 1));

    $this->actingAs($historicalOwner);
    $this->get('/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('integrations/index')->has('integrations.data', 0));
    $this->get('/diagnostic-runs/'.$run->id)->assertNotFound();
    $this->get('/findings/'.$finding->id)->assertNotFound();
});

it('allows only tenant operators and above with an active entitlement to request reanalysis', function () {
    config(['services.openai.key' => 'test-api-key']);
    Queue::fake();
    $historicalOwner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create(['name' => 'Reanalysis Role Workspace', 'slug' => 'reanalyze-role-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    [$integration, $run] = createForeignTenantDiagnosticRecords($historicalOwner, $tenant->id);

    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create(['user_id' => $viewer->id, 'role' => TenantMembership::ROLE_VIEWER, 'status' => TenantMembership::STATUS_ACTIVE, 'accepted_at' => now()]);
    $this->actingAs($viewer)->post('/diagnostic-runs/'.$run->id.'/reanalyze')->assertForbidden();
    expect($run->fresh()->status)->toBe('received');

    $operator = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create(['user_id' => $operator->id, 'role' => TenantMembership::ROLE_OPERATOR, 'status' => TenantMembership::STATUS_ACTIVE, 'accepted_at' => now()]);
    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_code' => 'approved-test-plan',
        'billing_mode' => 'manual',
        'status' => 'active',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'entitlements' => ['diagnostics.reanalyze' => true],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $this->actingAs($operator)->post('/diagnostic-runs/'.$run->id.'/reanalyze')->assertRedirect();
    Queue::assertPushed(AnalyzeDiagnosticRun::class, fn (AnalyzeDiagnosticRun $job): bool => $job->tenantId === $tenant->id && $job->ownerUserId === $operator->id);
    expect($run->fresh()->status)->toBe('queued');
});

it('restricts tenant audit exports to owner and admin and streams only tenant events', function () {
    $tenant = Tenant::create(['name' => 'Audit Export Workspace', 'slug' => 'audit-export-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    $viewer = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create(['user_id' => $viewer->id, 'role' => TenantMembership::ROLE_VIEWER, 'status' => TenantMembership::STATUS_ACTIVE, 'accepted_at' => now()]);

    $this->actingAs($viewer)->get('/tenants/'.$tenant->id.'/audit-events/export')->assertForbidden();

    $admin = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant->memberships()->create(['user_id' => $admin->id, 'role' => TenantMembership::ROLE_ADMIN, 'status' => TenantMembership::STATUS_ACTIVE, 'accepted_at' => now()]);
    $this->mock(AuditEventWriter::class)
        ->shouldReceive('append')->once()->with(Mockery::on(fn (array $event): bool => $event['tenant_id'] === $tenant->id && $event['event_type'] === 'tenant.audit_export.requested'));

    $response = $this->actingAs($admin)->get('/tenants/'.$tenant->id.'/audit-events/export');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('keeps tenant notification inbox entries private to the intended active member', function () {
    $tenant = Tenant::create(['name' => 'Notification Workspace', 'slug' => 'notification-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    $recipient = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $other = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    foreach ([$recipient, $other] as $user) {
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => TenantMembership::ROLE_VIEWER, 'status' => TenantMembership::STATUS_ACTIVE, 'accepted_at' => now()]);
    }

    $notification = app(TenantNotificationService::class)->publish($tenant->id, $recipient->id, 'diagnostics.run.completed', ['run_id' => 42, 'status' => 'completed'], 'diagnostic-run:42:completed');

    $duplicate = app(TenantNotificationService::class)->publish($tenant->id, $recipient->id, 'diagnostics.run.completed', ['run_id' => 42, 'status' => 'completed'], 'diagnostic-run:42:completed');
    expect($duplicate->id)->toBe($notification->id);

    $this->actingAs($recipient)->getJson('/tenants/'.$tenant->id.'/notifications')
        ->assertOk()->assertJsonPath('notifications.total', 1)
        ->assertJsonPath('notifications.data.0.id', $notification->id);
    $this->actingAs($other)->getJson('/tenants/'.$tenant->id.'/notifications')
        ->assertOk()->assertJsonPath('notifications.total', 0);
    $this->actingAs($other)->post('/tenants/'.$tenant->id.'/notifications/'.$notification->id.'/read')
        ->assertOk();
    expect($notification->fresh()->read_at)->toBeNull();

    $this->actingAs($recipient)->post('/tenants/'.$tenant->id.'/notifications/'.$notification->id.'/read')
        ->assertOk()->assertJsonPath('updated', true);
    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('does not publish notifications for suspended or non-member tenant users', function () {
    $tenant = Tenant::create(['name' => 'No Notification Workspace', 'slug' => 'no-notification-workspace', 'status' => Tenant::STATUS_ACTIVE]);
    $outsider = User::factory()->create();
    expect(fn () => app(TenantNotificationService::class)->publish($tenant->id, $outsider->id, 'tenant.notice.created', ['message' => 'test'], 'notice:outsider:001'))
        ->toThrow(ModelNotFoundException::class);

    $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
    expect(fn () => app(TenantNotificationService::class)->publish($tenant->id, $outsider->id, 'tenant.notice.created', ['message' => 'test'], 'notice:outsider:002'))
        ->toThrow(ModelNotFoundException::class);
});

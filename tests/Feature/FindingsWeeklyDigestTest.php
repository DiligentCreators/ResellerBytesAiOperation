<?php

use App\Mail\FindingsWeeklyDigestMail;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FindingsWeeklyDigestService;
use App\Services\Mail\CentralOutboundMailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('builds a weekly digest payload for a tenant', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Digest Workspace',
        'slug' => 'digest-'.uniqid(),
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
        'name' => 'Digest WHMCS',
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
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => ['whmcs_version' => '8.13.7']],
        'payload_sha256' => str_repeat('e', 64),
    ]);
    DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $tenant->id,
        'fingerprint' => hash('sha256', 'digest-finding'),
        'category' => 'security',
        'severity' => 'critical',
        'title' => 'TLS cipher suite outdated',
        'summary' => 'Weak ciphers remain enabled.',
        'evidence' => ['system.tls' => 'weak'],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    $digest = app(FindingsWeeklyDigestService::class)->buildDigest($tenant, now()->subDays(7));

    expect($digest['tenant_name'])->toBe('Digest Workspace')
        ->and($digest['open'])->toBe(1)
        ->and($digest['critical'])->toBe(1)
        ->and($digest['top_findings'][0]['title'])->toBe('TLS cipher suite outdated')
        ->and($digest['findings_url'])->toContain('/findings');

    $mailable = new FindingsWeeklyDigestMail($digest);
    expect($mailable->envelope()->subject)->toContain('1 active finding')
        ->and($mailable->render())->toContain('TLS cipher suite outdated');
});

it('sends weekly digests through platform mail for owner admin and operator roles only', function () {
    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'digest-owner@example.com',
        'email_verified_at' => now(),
    ]);
    $billing = User::factory()->withTwoFactor()->create([
        'email' => 'digest-billing@example.com',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::create([
        'name' => 'Mail Digest Workspace',
        'slug' => 'mail-digest-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $tenant->memberships()->create([
        'user_id' => $billing->id,
        'role' => TenantMembership::ROLE_BILLING,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $recipients = [];
    $mailer = Mockery::mock(CentralOutboundMailer::class);
    $mailer->shouldReceive('send')
        ->andReturnUsing(function (string $to) use (&$recipients): void {
            $recipients[] = $to;
        });
    app()->instance(CentralOutboundMailer::class, $mailer);

    $result = app(FindingsWeeklyDigestService::class)->send();

    expect($result['recipients'])->toBe(1)
        ->and($recipients)->toBe(['digest-owner@example.com']);
});

it('exposes the weekly digest artisan command', function () {
    $this->artisan('findings:send-weekly-digest')->assertSuccessful();
});

it('logs digests when platform mail driver is log', function () {
    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'log-digest@example.com',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::create([
        'name' => 'Log Digest Workspace',
        'slug' => 'log-digest-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    Log::spy();

    app(FindingsWeeklyDigestService::class)->send();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => $message === 'outbound_mail.preview')
        ->atLeast()
        ->once();
});

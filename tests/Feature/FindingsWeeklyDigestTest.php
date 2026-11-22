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
use App\Services\PlatformSettings;
use Illuminate\Support\Carbon;
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
        ->and($digest['awaiting_safe_actions'])->toBe(0)
        ->and($digest['needs_recheck'])->toBe(0)
        ->and($digest['top_findings'][0]['title'])->toBe('TLS cipher suite outdated')
        ->and($digest['findings_url'])->toContain('/findings')
        ->and($digest['safe_actions_url'])->toContain('/safe-actions');

    $mailable = new FindingsWeeklyDigestMail($digest);
    expect($mailable->envelope()->subject)->toContain('1 active finding')
        ->and($mailable->render())->toContain('TLS cipher suite outdated')
        ->and($mailable->render())->toContain('awaiting verify scan');
});

it('sends weekly digests through platform mail for owner admin and operator roles only', function () {
    Carbon::setTestNow('2026-11-16 09:30:00');
    app(PlatformSettings::class)->set('reports.weekly_day', 1);
    app(PlatformSettings::class)->set('reports.weekly_time', '09:00');

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
        'timezone' => 'UTC',
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

    Carbon::setTestNow();
});

it('exposes the weekly digest artisan command', function () {
    $this->artisan('findings:send-weekly-digest')->assertSuccessful();
});

it('sends weekly digest for one tenant with --tenant and --force even when not due', function () {
    Carbon::setTestNow('2026-11-17 10:00:00'); // Tuesday — not the default Monday schedule
    app(PlatformSettings::class)->set('reports.weekly_day', 1);
    app(PlatformSettings::class)->set('reports.weekly_time', '09:00');

    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'force-digest@example.com',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::create([
        'name' => 'Force Digest Workspace',
        'slug' => 'force-digest-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'timezone' => 'UTC',
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $otherOwner = User::factory()->withTwoFactor()->create([
        'email' => 'other-digest@example.com',
        'email_verified_at' => now(),
    ]);
    $other = Tenant::create([
        'name' => 'Other Digest Workspace',
        'slug' => 'other-digest-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'timezone' => 'UTC',
        'created_by_user_id' => $otherOwner->id,
    ]);
    $other->memberships()->create([
        'user_id' => $otherOwner->id,
        'role' => TenantMembership::ROLE_OWNER,
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

    $this->artisan('findings:send-weekly-digest', [
        '--tenant' => $tenant->id,
        '--force' => true,
    ])->assertSuccessful();

    expect($recipients)->toBe(['force-digest@example.com']);

    // Force bypasses idempotency for a second immediate send.
    $this->artisan('findings:send-weekly-digest', [
        '--tenant' => $tenant->id,
        '--force' => true,
    ])->assertSuccessful();

    expect($recipients)->toBe(['force-digest@example.com', 'force-digest@example.com']);

    Carbon::setTestNow();
});

it('fails findings:send-weekly-digest when --tenant does not match an active tenant', function () {
    $this->artisan('findings:send-weekly-digest', [
        '--tenant' => (string) Str::uuid(),
    ])
        ->expectsOutputToContain('No active tenant matched')
        ->assertFailed();
});

it('logs digests when platform mail driver is log', function () {
    Carbon::setTestNow('2026-11-16 09:30:00');
    app(PlatformSettings::class)->set('reports.weekly_day', 1);
    app(PlatformSettings::class)->set('reports.weekly_time', '09:00');

    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'log-digest@example.com',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::create([
        'name' => 'Log Digest Workspace',
        'slug' => 'log-digest-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'timezone' => 'UTC',
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

    Carbon::setTestNow();
});

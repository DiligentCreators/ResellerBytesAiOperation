<?php

use App\Mail\FindingsWeeklyDigestMail;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantReportSend;
use App\Models\User;
use App\Models\WhmcsHealthSnapshot;
use App\Models\WhmcsIntegration;
use App\Services\Mail\CentralOutboundMailer;
use App\Services\PlatformSettings;
use App\Services\Reports\WeeklyHealthReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

function createWeeklyReportWorkspace(?string $timezone = 'UTC', ?int $hourlyRate = 100): array
{
    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'weekly-owner-'.uniqid().'@example.com',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::create([
        'name' => 'Weekly Report Workspace',
        'slug' => 'weekly-report-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'timezone' => $timezone,
        'hourly_rate_usd' => $hourlyRate,
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
        'name' => 'Weekly WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
    ]);

    return compact('owner', 'tenant', 'integration');
}

it('builds weekly report content with health score time saved and fix links', function () {
    Carbon::setTestNow('2026-11-16 12:00:00'); // Monday
    $workspace = createWeeklyReportWorkspace();

    WhmcsHealthSnapshot::create([
        'id' => (string) Str::uuid(),
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'captured_at' => now('UTC')->subDays(8),
        'captured_on' => now('UTC')->subDays(8)->toDateString(),
        'score' => 70,
        'critical_count' => 1,
        'warning_count' => 0,
        'info_count' => 0,
        'metrics' => ['heartbeat_state' => 'ok'],
    ]);
    WhmcsHealthSnapshot::create([
        'id' => (string) Str::uuid(),
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'captured_at' => now('UTC')->subDay(),
        'captured_on' => now('UTC')->subDay()->toDateString(),
        'score' => 85,
        'critical_count' => 0,
        'warning_count' => 1,
        'info_count' => 0,
        'metrics' => ['heartbeat_state' => 'ok'],
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['whmcs' => ['billing' => ['recovered_paid_7d_total' => '120.50']]],
        'payload_sha256' => str_repeat('a', 64),
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'weekly-open'),
        'category' => 'security',
        'severity' => 'critical',
        'title' => 'TLS cipher suite outdated',
        'summary' => 'Weak ciphers remain enabled.',
        'evidence' => ['system.tls' => 'weak'],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'weekly-fixed'),
        'issue_key' => 'heartbeat.missed',
        'category' => 'availability',
        'severity' => 'critical',
        'title' => 'Heartbeat recovered',
        'summary' => 'Caught outside heartbeat window.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now()->subDay(),
    ]);

    SafeActionExecution::create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'service_unsuspend_paid',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_VERIFIED,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'minutes_saved' => 60,
        'requested_at' => now()->subDay(),
        'executed_at' => now()->subDay(),
        'verified_at' => now()->subDay(),
    ]);

    $periodStart = now('UTC')->subDays(6)->startOfDay();
    $digest = app(WeeklyHealthReportService::class)->buildDigest(
        $workspace['tenant'],
        $periodStart,
        now('UTC'),
    );

    expect($digest['health_score'])->toBe(85)
        ->and($digest['health_score_previous'])->toBe(70)
        ->and($digest['health_score_change'])->toBe(15)
        ->and($digest['issues_fixed'])->toBe(1)
        ->and($digest['time_saved_hours'])->toBe(1.0)
        ->and($digest['time_saved_value_display'])->toBe('100.00')
        ->and($digest['recovered_display'])->toBe('120.50')
        ->and($digest['outside_heartbeat_incidents'])->toBe(1)
        ->and($digest['top_open_issues'][0]['title'])->toBe('TLS cipher suite outdated')
        ->and($digest['top_open_issues'][0]['fix_url'])->toContain('/findings/')
        ->and($digest['installations'][0]['heartbeat_state'])->toBe('ok');

    $mailable = new FindingsWeeklyDigestMail($digest);
    expect($mailable->render())->toContain('Fix now')
        ->and($mailable->render())->toContain('Health score')
        ->and($mailable->render())->toContain('Time saved');

    Carbon::setTestNow();
});

it('respects platform weekly day and time in the workspace timezone', function () {
    config(['app.timezone' => 'UTC']);
    app(PlatformSettings::class)->set('reports.weekly_day', 1);
    app(PlatformSettings::class)->set('reports.weekly_time', '09:00');

    $workspace = createWeeklyReportWorkspace('America/New_York');
    $service = app(WeeklyHealthReportService::class);

    // Monday 08:00 America/New_York = Monday 13:00 UTC — before 09:00 local.
    expect($service->isDue($workspace['tenant'], Carbon::parse('2026-11-16 13:00:00', 'UTC')))->toBeFalse();

    // Monday 09:05 America/New_York = Monday 14:05 UTC — due.
    expect($service->isDue($workspace['tenant'], Carbon::parse('2026-11-16 14:05:00', 'UTC')))->toBeTrue();

    // Tuesday — wrong day.
    expect($service->isDue($workspace['tenant'], Carbon::parse('2026-11-17 15:00:00', 'UTC')))->toBeFalse();
});

it('sends weekly reports once and records tenant_report_sends idempotently', function () {
    Carbon::setTestNow('2026-11-16 09:30:00'); // Monday after 09:00 UTC
    app(PlatformSettings::class)->set('reports.weekly_day', 1);
    app(PlatformSettings::class)->set('reports.weekly_time', '09:00');

    $workspace = createWeeklyReportWorkspace('UTC');

    $recipients = [];
    $mailer = Mockery::mock(CentralOutboundMailer::class);
    $mailer->shouldReceive('send')
        ->andReturnUsing(function (string $to) use (&$recipients): void {
            $recipients[] = $to;
        });
    app()->instance(CentralOutboundMailer::class, $mailer);

    $first = app(WeeklyHealthReportService::class)->send(now('UTC'));
    $second = app(WeeklyHealthReportService::class)->send(now('UTC'));

    expect($first['recipients'])->toBe(1)
        ->and($second['recipients'])->toBe(0)
        ->and($second['skipped_idempotent'])->toBeGreaterThan(0)
        ->and(TenantReportSend::query()->where('tenant_id', $workspace['tenant']->id)->count())->toBe(1)
        ->and($recipients)->toHaveCount(1);

    Carbon::setTestNow();
});

it('handles workspaces with no findings or fixes without failing', function () {
    Carbon::setTestNow('2026-11-16 09:30:00');
    app(PlatformSettings::class)->set('reports.weekly_day', 1);
    app(PlatformSettings::class)->set('reports.weekly_time', '09:00');

    createWeeklyReportWorkspace('UTC');

    $mailer = Mockery::mock(CentralOutboundMailer::class);
    $mailer->shouldReceive('send')->once();
    app()->instance(CentralOutboundMailer::class, $mailer);

    $result = app(WeeklyHealthReportService::class)->send(now('UTC'));

    expect($result['recipients'])->toBe(1)
        ->and($result['skipped_clean'])->toBe(1);

    Carbon::setTestNow();
});

it('keeps findings:send-weekly-digest command working', function () {
    $this->artisan('findings:send-weekly-digest')->assertSuccessful();
});

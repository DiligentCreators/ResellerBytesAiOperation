<?php

use App\Mail\MonthlyValueReportMail;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantReportSend;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Mail\CentralOutboundMailer;
use App\Services\PlatformSettings;
use App\Services\Reports\MonthlyValueReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createValueReportWorkspace(bool $entitled = true, ?Carbon $periodEndsAt = null): array
{
    $owner = User::factory()->withTwoFactor()->create([
        'email' => 'value-owner-'.uniqid().'@example.com',
        'email_verified_at' => now(),
    ]);
    $tenant = Tenant::create([
        'name' => 'Value Report Workspace',
        'slug' => 'value-report-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'timezone' => 'UTC',
        'hourly_rate_usd' => 50,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $plan = Plan::factory()->create([
        'code' => 'growth-value-'.uniqid(),
        'entitlements' => [
            'diagnostics.analysis' => true,
            'reports.value' => $entitled,
            'limits' => [
                'members' => 5,
                'integrations' => 2,
                'diagnostics_per_period' => 100,
                'retention_days' => 30,
                'history_days' => 90,
            ],
        ],
    ]);

    $endsAt = $periodEndsAt ?? now('UTC')->addDays(3);
    $subscription = TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'plan_code' => $plan->code,
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 4900,
        'entitlements' => $plan->entitlements,
        'current_period_starts_at' => $endsAt->copy()->subMonth(),
        'current_period_ends_at' => $endsAt,
    ]);

    return compact('owner', 'tenant', 'plan', 'subscription');
}

it('sends the monthly value report only for entitled workspaces N days before renewal', function () {
    Carbon::setTestNow('2026-11-19 10:00:00');
    app(PlatformSettings::class)->set('reports.value_report_days_before_renewal', 3);

    $pro = createValueReportWorkspace(true, Carbon::parse('2026-11-22 00:00:00', 'UTC'));
    $starter = createValueReportWorkspace(false, Carbon::parse('2026-11-22 00:00:00', 'UTC'));
    $wrongWindow = createValueReportWorkspace(true, Carbon::parse('2026-11-25 00:00:00', 'UTC'));

    $recipients = [];
    $mailer = Mockery::mock(CentralOutboundMailer::class);
    $mailer->shouldReceive('send')
        ->andReturnUsing(function (string $to) use (&$recipients): void {
            $recipients[] = $to;
        });
    app()->instance(CentralOutboundMailer::class, $mailer);

    $result = app(MonthlyValueReportService::class)->send(now('UTC'));

    expect($result['recipients'])->toBe(1)
        ->and($recipients)->toBe([$pro['owner']->email])
        ->and(TenantReportSend::query()->where('tenant_id', $pro['tenant']->id)->where('type', TenantReportSend::TYPE_MONTHLY_VALUE)->count())->toBe(1)
        ->and(TenantReportSend::query()->where('tenant_id', $starter['tenant']->id)->count())->toBe(0)
        ->and(TenantReportSend::query()->where('tenant_id', $wrongWindow['tenant']->id)->count())->toBe(0);

    $again = app(MonthlyValueReportService::class)->send(now('UTC'));
    expect($again['recipients'])->toBe(0)
        ->and($again['skipped_idempotent'])->toBeGreaterThan(0);

    Carbon::setTestNow();
});

it('exposes the reports:send-value-report artisan command', function () {
    $this->artisan('reports:send-value-report')->assertSuccessful();
});

it('sends a value report for one entitled tenant with --tenant and --force outside the window', function () {
    Carbon::setTestNow('2026-11-10 10:00:00');
    app(PlatformSettings::class)->set('reports.value_report_days_before_renewal', 3);

    $pro = createValueReportWorkspace(true, Carbon::parse('2026-11-22 00:00:00', 'UTC'));
    $starter = createValueReportWorkspace(false, Carbon::parse('2026-11-22 00:00:00', 'UTC'));

    $recipients = [];
    $mailer = Mockery::mock(CentralOutboundMailer::class);
    $mailer->shouldReceive('send')
        ->andReturnUsing(function (string $to) use (&$recipients): void {
            $recipients[] = $to;
        });
    app()->instance(CentralOutboundMailer::class, $mailer);

    $this->artisan('reports:send-value-report', [
        '--tenant' => $pro['tenant']->id,
        '--force' => true,
    ])->assertSuccessful();

    expect($recipients)->toBe([$pro['owner']->email])
        ->and(TenantReportSend::query()->where('tenant_id', $pro['tenant']->id)->where('type', TenantReportSend::TYPE_MONTHLY_VALUE)->count())->toBe(1);

    // Entitlement gating still applies under --force.
    $this->artisan('reports:send-value-report', [
        '--tenant' => $starter['tenant']->id,
        '--force' => true,
    ])->assertSuccessful();

    expect($recipients)->toBe([$pro['owner']->email])
        ->and(TenantReportSend::query()->where('tenant_id', $starter['tenant']->id)->count())->toBe(0);

    // Force bypasses idempotency for a second immediate send.
    $this->artisan('reports:send-value-report', [
        '--tenant' => $pro['tenant']->id,
        '--force' => true,
    ])->assertSuccessful();

    expect($recipients)->toBe([$pro['owner']->email, $pro['owner']->email]);

    Carbon::setTestNow();
});

it('fails reports:send-value-report when --tenant does not match an active tenant', function () {
    $this->artisan('reports:send-value-report', [
        '--tenant' => (string) Str::uuid(),
    ])
        ->expectsOutputToContain('No active tenant matched')
        ->assertFailed();
});

it('skips value reports for workspaces with no active subscription data safely', function () {
    Carbon::setTestNow('2026-11-19 10:00:00');
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    Tenant::create([
        'name' => 'Empty Value Workspace',
        'slug' => 'empty-value-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ])->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $result = app(MonthlyValueReportService::class)->send(now('UTC'));

    expect($result['recipients'])->toBe(0);

    Carbon::setTestNow();
});

it('uses a last-30-days window for yearly subscriptions, matching metrics and header period', function () {
    Carbon::setTestNow('2026-11-22 10:00:00');
    app(PlatformSettings::class)->set('reports.value_report_days_before_renewal', 3);

    $workspace = createValueReportWorkspace(true, Carbon::parse('2026-11-25 00:00:00', 'UTC'));
    $workspace['subscription']->forceFill([
        'current_period_starts_at' => Carbon::parse('2025-11-25 00:00:00', 'UTC'),
        'current_period_ends_at' => Carbon::parse('2026-11-25 00:00:00', 'UTC'),
    ])->save();

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $workspace['owner']->id,
        'tenant_id' => $workspace['tenant']->id,
        'name' => 'Value WHMCS',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => [],
        'payload_sha256' => hash('sha256', 'value-report-'.uniqid()),
    ]);

    // Inside the rolling 30-day window.
    DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'inside-window|'.uniqid()),
        'category' => 'security',
        'severity' => 'warning',
        'title' => 'Fixed recently',
        'summary' => 'Fixed recently',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now('UTC')->subDays(5),
    ]);

    // Inside the yearly subscription term, but outside the last 30 days.
    DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'outside-window|'.uniqid()),
        'category' => 'security',
        'severity' => 'warning',
        'title' => 'Fixed months ago',
        'summary' => 'Fixed months ago',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'resolved_at' => now('UTC')->subDays(90),
    ]);

    $bodies = [];
    $mailer = Mockery::mock(CentralOutboundMailer::class);
    $mailer->shouldReceive('send')
        ->andReturnUsing(function (string $to, string $subject, string $html) use (&$bodies): void {
            $bodies[] = $html;
        });
    app()->instance(CentralOutboundMailer::class, $mailer);

    $service = app(MonthlyValueReportService::class);
    $period = $service->metricsPeriodFor(now('UTC'));
    $expectedLabel = $service->periodLabel($period['start'], $period['end']);

    $result = $service->send(now('UTC'));

    expect($result['recipients'])->toBe(1);

    $report = TenantReportSend::query()
        ->where('tenant_id', $workspace['tenant']->id)
        ->where('type', TenantReportSend::TYPE_MONTHLY_VALUE)
        ->sole();

    expect($report->period_start->toDateString())->toBe($period['start']->toDateString())
        ->and($report->period_end->toDateString())->toBe($period['end']->toDateString())
        ->and($report->period_start->toDateString())->toBe('2026-10-23')
        ->and($report->period_end->toDateString())->toBe('2026-11-22')
        ->and($report->period_start->toDateString())->not->toBe('2025-11-25')
        ->and($report->summary['period_start'])->toBe($report->period_start->toDateString())
        ->and($report->summary['period_end'])->toBe($report->period_end->toDateString())
        ->and($report->summary['period_label'])->toBe($expectedLabel)
        ->and($report->summary['issues_fixed'])->toBe(1)
        ->and($expectedLabel)->toBe('Last 30 days: Oct 23 - Nov 22, 2026')
        ->and($bodies[0] ?? '')->toContain($expectedLabel);

    $mail = new MonthlyValueReportMail($report->summary);
    expect($mail->render())->toContain($expectedLabel);

    Carbon::setTestNow();
});

it('labels old year-long value reports with a plain date range on the page and in resend mail', function () {
    $this->withoutVite();
    $workspace = createValueReportWorkspace(true);

    $summary = [
        'tenant_name' => $workspace['tenant']->name,
        'period_start' => '2026-11-21',
        'period_end' => '2027-11-22',
        'issues_fixed' => 3,
        'time_saved_hours_display' => '1.0',
        'time_saved_value_display' => '50.00',
        'outside_heartbeat_incidents' => 0,
        'reports_url' => '/reports',
        'findings_url' => '/findings',
    ];

    $report = TenantReportSend::factory()->monthlyValue()->create([
        'tenant_id' => $workspace['tenant']->id,
        'period_start' => '2026-11-21',
        'period_end' => '2027-11-22',
        'sent_at' => now('UTC'),
        'summary' => $summary,
    ]);

    $expectedPlain = 'Nov 21, 2026 - Nov 22, 2027';

    $this->actingAs($workspace['owner'])
        ->get(route('reports.show', ['tenant' => $workspace['tenant'], 'report' => $report]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/show')
            ->where('report.period_label', $expectedPlain)
            ->where('report.period_start', '2026-11-21')
            ->where('report.period_end', '2027-11-22'));

    $mail = new MonthlyValueReportMail($summary);
    $html = $mail->render();

    expect($html)->toContain($expectedPlain)
        ->and($html)->not->toContain('Last 30 days');
});

it('labels new 30-day value reports with Last 30 days on the page and in resend mail', function () {
    $this->withoutVite();
    $workspace = createValueReportWorkspace(true);

    $expectedLabel = 'Last 30 days: Oct 23 - Nov 22, 2026';
    $summary = [
        'tenant_name' => $workspace['tenant']->name,
        'period_start' => '2026-10-23',
        'period_end' => '2026-11-22',
        'period_label' => $expectedLabel,
        'issues_fixed' => 1,
        'time_saved_hours_display' => '0.5',
        'time_saved_value_display' => '25.00',
        'outside_heartbeat_incidents' => 0,
        'reports_url' => '/reports',
        'findings_url' => '/findings',
    ];

    $report = TenantReportSend::factory()->monthlyValue()->create([
        'tenant_id' => $workspace['tenant']->id,
        'period_start' => '2026-10-23',
        'period_end' => '2026-11-22',
        'sent_at' => now('UTC'),
        'summary' => $summary,
    ]);

    $this->actingAs($workspace['owner'])
        ->get(route('reports.show', ['tenant' => $workspace['tenant'], 'report' => $report]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/show')
            ->where('report.period_label', $expectedLabel));

    $mail = new MonthlyValueReportMail($summary);
    expect($mail->render())->toContain($expectedLabel);
});

it('uses Last 30 days for short spans without an explicit period_label', function () {
    $summary = [
        'tenant_name' => 'Legacy Short Window',
        'period_start' => '2026-10-23',
        'period_end' => '2026-11-22',
        'issues_fixed' => 0,
    ];

    $mail = new MonthlyValueReportMail($summary);

    expect($mail->render())->toContain('Last 30 days: Oct 23 - Nov 22, 2026');
});

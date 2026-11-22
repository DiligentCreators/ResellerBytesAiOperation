<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantReportSend;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Mail\CentralOutboundMailer;
use App\Services\PlatformSettings;
use App\Services\Reports\MonthlyValueReportService;
use Illuminate\Support\Carbon;

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

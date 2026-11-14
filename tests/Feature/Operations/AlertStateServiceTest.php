<?php

declare(strict_types=1);

use App\Services\Operations\AlertNotificationDispatcher;
use App\Services\Operations\AlertStateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['operations.alerts.repeat_interval_minutes' => 15]);
});

it('deduplicates active alerts until the configured repeat interval elapses', function () {
    $dispatcher = Mockery::mock(AlertNotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->twice()
        ->andReturn(
            ['success' => true, 'deliveries' => ['email' => 'delivered', 'team_channel' => 'delivered']],
            ['success' => true, 'deliveries' => ['email' => 'delivered', 'team_channel' => 'delivered']],
        );
    app()->instance(AlertNotificationDispatcher::class, $dispatcher);

    $alert = [
        'code' => 'error_rate_exceeded',
        'severity' => 'alert',
        'observed' => 2.5,
        'threshold' => 2,
        'window_minutes' => 5,
    ];
    $start = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $service = app(AlertStateService::class);

    $first = $service->process([$alert], $start);
    $insideInterval = $service->process([$alert], $start->addMinutes(5));
    $afterInterval = $service->process([$alert], $start->addMinutes(15));

    expect($first['notifications_sent'])->toBe(1)
        ->and($insideInterval['notifications_sent'])->toBe(0)
        ->and($afterInterval['notifications_sent'])->toBe(1)
        ->and(DB::table('operations_alert_states')->value('notification_count'))->toBe(2);
});

it('retries failed delivery and re-notifies after an alert resolves and returns', function () {
    $dispatcher = Mockery::mock(AlertNotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->twice()
        ->andReturn(
            ['success' => false, 'deliveries' => ['email' => 'delivered', 'team_channel' => 'failed']],
            ['success' => true, 'deliveries' => ['email' => 'delivered', 'team_channel' => 'delivered']],
        );
    app()->instance(AlertNotificationDispatcher::class, $dispatcher);

    $alert = [
        'code' => 'disk_usage_critical',
        'severity' => 'critical',
        'observed' => 91,
        'threshold' => 90,
    ];
    $start = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $service = app(AlertStateService::class);

    $failed = $service->process([$alert], $start);
    $retried = $service->process([$alert], $start->addMinute());
    $service->process([], $start->addMinutes(2));
    $inactive = DB::table('operations_alert_states')->where('code', $alert['code'])->first();

    expect($failed['notifications_failed'])->toBe(1)
        ->and($retried['notifications_sent'])->toBe(1)
        ->and((bool) $inactive->is_active)->toBeFalse();

    $reactivatingDispatcher = Mockery::mock(AlertNotificationDispatcher::class);
    $reactivatingDispatcher->shouldReceive('dispatch')
        ->once()
        ->andReturn(['success' => true, 'deliveries' => ['email' => 'delivered', 'team_channel' => 'delivered']]);
    app()->instance(AlertNotificationDispatcher::class, $reactivatingDispatcher);
    $service = app(AlertStateService::class);

    $reactivated = $service->process([$alert], $start->addMinutes(3));

    expect($reactivated['notifications_sent'])->toBe(1)
        ->and((bool) DB::table('operations_alert_states')->where('code', $alert['code'])->value('is_active'))->toBeTrue();
});

it('does not resolve an alert when its metric is unavailable but resolves it on a known healthy sample', function () {
    $dispatcher = Mockery::mock(AlertNotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->andReturn(['success' => true, 'deliveries' => ['email' => 'delivered', 'team_channel' => 'delivered']]);
    app()->instance(AlertNotificationDispatcher::class, $dispatcher);

    $alert = [
        'code' => 'error_rate_exceeded',
        'severity' => 'alert',
        'observed' => 2.5,
        'threshold' => 2,
        'window_minutes' => 5,
    ];
    $start = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $service = app(AlertStateService::class);

    $service->process([$alert], $start, ['error_rate_exceeded']);
    $service->process([], $start->addMinute(), []);

    expect((bool) DB::table('operations_alert_states')->where('code', $alert['code'])->value('is_active'))
        ->toBeTrue();

    $service->process([], $start->addMinutes(2), ['error_rate_exceeded']);

    expect((bool) DB::table('operations_alert_states')->where('code', $alert['code'])->value('is_active'))
        ->toBeFalse();
});

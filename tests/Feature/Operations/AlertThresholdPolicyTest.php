<?php

declare(strict_types=1);

use App\Services\Operations\AlertThresholdPolicy;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config([
        'operations.monitoring.error_rate.window_minutes' => 5,
        'operations.monitoring.error_rate.threshold_percent' => 2,
        'operations.monitoring.api_p95_latency_ms' => 1500,
        'operations.monitoring.backup_max_age_hours' => 26,
        'operations.monitoring.disk.warning_percent' => 80,
        'operations.monitoring.disk.critical_percent' => 90,
        'operations.monitoring.queue_job_max_age_minutes' => 15,
    ]);
});

it('does not alert at exact non-inclusive error, latency, and queue thresholds', function () {
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');

    $alerts = app(AlertThresholdPolicy::class)->evaluate([
        'error_rate_percent' => 2,
        'api_p95_latency_ms' => 1500,
        'last_successful_backup_at' => $observedAt->subHours(25)->toIso8601String(),
        'disk_usage_percent' => 79.9,
        'oldest_queue_job_age_minutes' => 15,
    ], $observedAt);

    expect($alerts)->toBe([]);
});

it('alerts when error rate, API p95, or queue age exceeds its threshold', function () {
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');

    $alerts = app(AlertThresholdPolicy::class)->evaluate([
        'error_rate_percent' => 2.01,
        'api_p95_latency_ms' => 1501,
        'last_successful_backup_at' => $observedAt->subHours(25)->toIso8601String(),
        'disk_usage_percent' => 79,
        'oldest_queue_job_age_minutes' => 15.1,
    ], $observedAt);

    expect(array_column($alerts, 'code'))->toBe([
        'error_rate_exceeded',
        'api_p95_latency_exceeded',
        'queue_job_age_exceeded',
    ])->and($alerts[0]['window_minutes'])->toBe(5);
});

it('warns at 80 percent disk and escalates to critical at 90 percent', function () {
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $policy = app(AlertThresholdPolicy::class);

    $warning = $policy->evaluate([
        'disk_usage_percent' => 80,
        'last_successful_backup_at' => $observedAt->subHours(1)->toIso8601String(),
    ], $observedAt);

    $critical = $policy->evaluate([
        'disk_usage_percent' => 90,
        'last_successful_backup_at' => $observedAt->subHours(1)->toIso8601String(),
    ], $observedAt);

    expect($warning[0]['severity'])->toBe('warning')
        ->and($warning[0]['code'])->toBe('disk_usage_warning')
        ->and($critical[0]['severity'])->toBe('critical')
        ->and($critical[0]['code'])->toBe('disk_usage_critical');
});

it('alerts when no successful backup exists or the last backup reaches 26 hours', function () {
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $policy = app(AlertThresholdPolicy::class);

    $missing = $policy->evaluate([], $observedAt);
    $stale = $policy->evaluate([
        'last_successful_backup_at' => $observedAt->subHours(26)->toIso8601String(),
    ], $observedAt);

    expect($missing[0]['code'])->toBe('successful_backup_missing')
        ->and($stale[0]['code'])->toBe('successful_backup_stale')
        ->and($stale[0]['observed'])->toBe(26.0);
});

it('fails closed on an invalid or future successful-backup timestamp', function () {
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $policy = app(AlertThresholdPolicy::class);

    $invalid = $policy->evaluate(['last_successful_backup_at' => 'not-a-date'], $observedAt);
    $future = $policy->evaluate([
        'last_successful_backup_at' => $observedAt->addMinute()->toIso8601String(),
    ], $observedAt);

    expect($invalid[0]['code'])->toBe('successful_backup_timestamp_invalid')
        ->and($future[0]['code'])->toBe('successful_backup_timestamp_invalid');
});

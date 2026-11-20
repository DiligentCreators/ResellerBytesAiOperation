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

it('marks only metrics with an observed value as safe to resolve', function () {
    $policy = app(AlertThresholdPolicy::class);

    expect($policy->evaluatedCodes([
        'error_rate_percent' => null,
        'api_p95_latency_ms' => null,
        'last_successful_backup_at' => null,
        'disk_usage_percent' => null,
        'oldest_queue_job_age_minutes' => null,
    ]))->toBe([
        'successful_backup_missing',
        'successful_backup_stale',
        'successful_backup_timestamp_invalid',
    ]);

    expect($policy->evaluatedCodes([
        'error_rate_percent' => 0,
        'api_p95_latency_ms' => 0,
        'last_successful_backup_at' => '2026-11-14T11:00:00Z',
        'disk_usage_percent' => 20,
        'oldest_queue_job_age_minutes' => 0,
    ]))->toBe([
        'error_rate_exceeded',
        'api_p95_latency_exceeded',
        'successful_backup_missing',
        'successful_backup_stale',
        'successful_backup_timestamp_invalid',
        'disk_usage_warning',
        'disk_usage_critical',
        'queue_job_age_exceeded',
    ]);
});

it('raises billing alerts for dead letters, stale leases and backlog older than 15 minutes', function () {
    $metrics = [
        'last_successful_backup_at' => now()->toIso8601String(),
        'billing_webhook_dead_letter_count' => 1, 'billing_outbox_dead_letter_count' => 0,
        'billing_webhook_stale_processing_count' => 0, 'billing_outbox_stale_processing_count' => 2,
        'billing_webhook_oldest_pending_age_minutes' => 0, 'billing_outbox_oldest_pending_age_minutes' => 15.1,
    ];

    expect(array_column(app(AlertThresholdPolicy::class)->evaluate($metrics), 'code'))->toBe([
        'billing_webhook_dead_lettered', 'billing_outbox_processing_stale', 'billing_outbox_backlog_age_exceeded',
    ]);
    expect(app(AlertThresholdPolicy::class)->evaluatedCodes($metrics))->toContain(
        'billing_webhook_dead_lettered', 'billing_webhook_processing_stale', 'billing_webhook_backlog_age_exceeded',
        'billing_outbox_dead_lettered', 'billing_outbox_processing_stale', 'billing_outbox_backlog_age_exceeded',
    );
});

it('does not resolve billing alerts when operations telemetry is unavailable', function () {
    $metrics = [
        'last_successful_backup_at' => now()->toIso8601String(),
        'billing_webhook_dead_letter_count' => null, 'billing_outbox_dead_letter_count' => null,
        'billing_webhook_stale_processing_count' => null, 'billing_outbox_stale_processing_count' => null,
        'billing_webhook_oldest_pending_age_minutes' => null, 'billing_outbox_oldest_pending_age_minutes' => null,
    ];

    expect(app(AlertThresholdPolicy::class)->evaluate($metrics))->toBe([])
        ->and(app(AlertThresholdPolicy::class)->evaluatedCodes($metrics))->toBe([
            'successful_backup_missing',
            'successful_backup_stale',
            'successful_backup_timestamp_invalid',
        ]);
});

it('alerts on stale in-flight workers, resolves on healthy telemetry, and preserves alerts when telemetry is missing', function () {
    $policy = app(AlertThresholdPolicy::class);

    $alerts = $policy->evaluate([
        'last_successful_backup_at' => now()->toIso8601String(),
        'oldest_processing_job_age_minutes' => 15.1,
    ]);

    expect(array_column($alerts, 'code'))->toContain('queue_job_processing_stale')
        ->and($policy->evaluate([
            'last_successful_backup_at' => now()->toIso8601String(),
            'oldest_processing_job_age_minutes' => 15,
        ]))->not->toContain('queue_job_processing_stale')
        ->and($policy->evaluatedCodes([
            'oldest_processing_job_age_minutes' => 0,
        ]))->toContain('queue_job_processing_stale')
        ->and($policy->evaluatedCodes([]))->not->toContain('queue_job_processing_stale');
});

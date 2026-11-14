<?php

declare(strict_types=1);

use App\Http\Middleware\CaptureOperationalMetrics;
use App\Services\Operations\OperationalMetricsSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'queue.default' => 'sync',
        'operations.metrics.backup_success_marker_path' => null,
        'operations.metrics.disk_path' => storage_path(),
        'operations.monitoring.error_rate.window_minutes' => 5,
    ]);
});

it('aggregates five-minute API error rate and p95 latency without request payload data', function () {
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');

    DB::table('operations_metric_samples')->insert([
        ['status_code' => 200, 'duration_ms' => 100, 'created_at' => $observedAt->subMinutes(2)],
        ['status_code' => 500, 'duration_ms' => 200, 'created_at' => $observedAt->subMinute()],
        ['status_code' => 503, 'duration_ms' => 300, 'created_at' => $observedAt],
        ['status_code' => 200, 'duration_ms' => 900, 'created_at' => $observedAt->subMinutes(6)],
    ]);

    $metrics = app(OperationalMetricsSource::class)->snapshot($observedAt);

    expect($metrics['error_rate_percent'])->toBe((2 / 3) * 100)
        ->and($metrics['api_p95_latency_ms'])->toBe(300.0)
        ->and($metrics['last_successful_backup_at'])->toBeNull()
        ->and($metrics['disk_usage_percent'])->toBeFloat()
        ->and($metrics['oldest_queue_job_age_minutes'])->toBeNull();
});

it('captures API response status and duration without storing request content', function () {
    $request = Request::create('/api/metrics-probe', 'POST', [], [], [], [], 'customer-secret-payload');
    $response = app(CaptureOperationalMetrics::class)->handle(
        $request,
        static fn () => response('unavailable', 503),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and(DB::table('operations_metric_samples')->count())->toBe(1)
        ->and(DB::table('operations_metric_samples')->value('status_code'))->toBe(503)
        ->and(DB::getSchemaBuilder()->getColumnListing('operations_metric_samples'))
        ->not->toContain('payload');
});

it('reads the backup success marker modification time and prunes samples older than one day', function () {
    $markerPath = tempnam(sys_get_temp_dir(), 'rb-backup-marker-');

    expect($markerPath)->toBeString();

    file_put_contents($markerPath, 'backup-success');
    touch($markerPath, CarbonImmutable::parse('2026-11-14T10:00:00Z')->getTimestamp());
    config(['operations.metrics.backup_success_marker_path' => $markerPath]);

    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    DB::table('operations_metric_samples')->insert([
        ['status_code' => 200, 'duration_ms' => 10, 'created_at' => $observedAt->subDays(2)],
        ['status_code' => 200, 'duration_ms' => 20, 'created_at' => $observedAt->subHours(1)],
    ]);

    $source = app(OperationalMetricsSource::class);

    expect($source->snapshot($observedAt)['last_successful_backup_at'])
        ->toBe('2026-11-14T10:00:00+00:00')
        ->and($source->pruneSamples($observedAt))->toBe(1)
        ->and(DB::table('operations_metric_samples')->count())->toBe(1);

    @unlink($markerPath);
});

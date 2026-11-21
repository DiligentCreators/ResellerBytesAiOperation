<?php

declare(strict_types=1);

use App\Http\Middleware\CaptureOperationalMetrics;
use App\Models\Tenant;
use App\Services\Operations\OperationalMetricsSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

beforeEach(function () {
    config([
        'queue.default' => 'sync',
        'operations.metrics.backup_success_marker_path' => null,
        'operations.metrics.disk_path' => storage_path(),
        'operations.monitoring.error_rate.window_minutes' => 5,
    ]);
});

it('aggregates five-minute API error rate and p95 latency without request payload data', function () {
    $tenant = Tenant::create(['name' => 'Metrics processing tenant', 'slug' => 'metrics-processing-tenant', 'status' => Tenant::STATUS_ACTIVE]);
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');

    DB::table('operations_metric_samples')->insert([
        ['status_code' => 200, 'duration_ms' => 100, 'created_at' => $observedAt->subMinutes(2)],
        ['status_code' => 500, 'duration_ms' => 200, 'created_at' => $observedAt->subMinute()],
        ['status_code' => 503, 'duration_ms' => 300, 'created_at' => $observedAt],
        ['status_code' => 200, 'duration_ms' => 900, 'created_at' => $observedAt->subMinutes(6)],
    ]);

    DB::table('tenant_billing_outbox')->insert([
        'tenant_id' => $tenant->id, 'idempotency_key' => 'metrics-outbox-processing',
        'event_type' => 'billing.invoice.issued', 'aggregate_type' => 'billing_invoice', 'aggregate_id' => '2',
        'payload' => json_encode(['private' => 'never-return'], JSON_THROW_ON_ERROR), 'status' => 'processing',
        'attempts' => 1, 'manual_replay_count' => 0, 'available_at' => $observedAt,
        'locked_at' => $observedAt->subMinutes(8), 'created_at' => $observedAt->subMinutes(8),
        'updated_at' => $observedAt->subMinutes(8),
    ]);
    DB::table('tenant_billing_webhook_events')->insert([
        'tenant_id' => $tenant->id, 'provider' => 'test_gateway', 'provider_account_id' => 'private-account',
        'provider_event_id' => 'metrics-webhook-processing', 'event_type' => 'invoice.payment_failed',
        'payload_sha256' => hash('sha256', 'payload-processing'),
        'payload' => json_encode(['private' => 'never-return'], JSON_THROW_ON_ERROR), 'status' => 'processing',
        'attempts' => 1, 'manual_replay_count' => 0, 'received_at' => $observedAt->subMinutes(8),
        'available_at' => $observedAt, 'locked_at' => $observedAt->subMinutes(8),
        'created_at' => $observedAt->subMinutes(8), 'updated_at' => $observedAt->subMinutes(8),
    ]);

    $metrics = app(OperationalMetricsSource::class)->snapshot($observedAt);

    expect($metrics['error_rate_percent'])->toBe((2 / 3) * 100)
        ->and($metrics['api_p95_latency_ms'])->toBe(300.0)
        ->and($metrics['last_successful_backup_at'])->toBeNull()
        ->and($metrics['disk_usage_percent'])->toBeFloat()
        ->and($metrics['oldest_queue_job_age_minutes'])->toBeNull()
        ->and($metrics['oldest_processing_job_age_minutes'])->toBeNull();
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

it('uses the verified backup marker timestamp rather than its filesystem modification time', function () {
    $markerPath = tempnam(sys_get_temp_dir(), 'rb-backup-marker-');

    expect($markerPath)->toBeString();

    file_put_contents($markerPath, json_encode([
        'backup_id' => '550e8400-e29b-41d4-a716-446655440000',
        'created_at' => '2026-11-14T10:00:00+00:00',
    ], JSON_THROW_ON_ERROR));
    touch($markerPath, CarbonImmutable::parse('2026-11-14T11:30:00Z')->getTimestamp());
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

it('treats malformed and future backup success markers as unavailable', function () {
    $markerPath = tempnam(sys_get_temp_dir(), 'rb-backup-marker-');
    expect($markerPath)->toBeString();
    config(['operations.metrics.backup_success_marker_path' => $markerPath]);

    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $source = app(OperationalMetricsSource::class);

    file_put_contents($markerPath, '{"backup_id":"not-a-uuid","created_at":"2026-11-14T10:00:00+00:00"}');
    expect($source->snapshot($observedAt)['last_successful_backup_at'])->toBeNull();

    file_put_contents($markerPath, json_encode([
        'backup_id' => '550e8400-e29b-41d4-a716-446655440000',
        'created_at' => '2026-11-14T12:06:00+00:00',
    ], JSON_THROW_ON_ERROR));
    expect($source->snapshot($observedAt)['last_successful_backup_at'])->toBeNull();

    file_put_contents($markerPath, json_encode([
        'backup_id' => '550e8400-e29b-41d4-a716-446655440000',
        'created_at' => '2026-02-30T10:00:00+00:00',
    ], JSON_THROW_ON_ERROR));
    expect($source->snapshot($observedAt)['last_successful_backup_at'])->toBeNull();

    @unlink($markerPath);
});

it('schedules hourly pruning of operational metric samples', function () {
    $scheduled = collect(Schedule::events())->contains(
        static fn ($event): bool => str_contains((string) $event->command, 'operations:metrics'),
    );

    expect($scheduled)->toBeTrue();
});

it('collects billing queue health as aggregate-only operational metrics', function () {
    $tenant = Tenant::create(['name' => 'Metrics tenant', 'slug' => 'metrics-tenant', 'status' => Tenant::STATUS_ACTIVE]);
    $observedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');

    DB::table('tenant_billing_outbox')->insert([
        'tenant_id' => $tenant->id, 'idempotency_key' => 'metrics-outbox-pending',
        'event_type' => 'billing.invoice.issued', 'aggregate_type' => 'billing_invoice', 'aggregate_id' => '1',
        'payload' => json_encode(['private' => 'never-return'], JSON_THROW_ON_ERROR), 'status' => 'pending',
        'attempts' => 2, 'manual_replay_count' => 0, 'available_at' => $observedAt->subMinutes(20),
        'created_at' => $observedAt->subMinutes(20), 'updated_at' => $observedAt->subMinutes(20),
    ]);
    DB::table('tenant_billing_webhook_events')->insert([
        'tenant_id' => $tenant->id, 'provider' => 'test_gateway', 'provider_account_id' => 'private-account',
        'provider_event_id' => 'metrics-webhook-dead', 'event_type' => 'invoice.payment_failed',
        'payload_sha256' => hash('sha256', 'payload'),
        'payload' => json_encode(['private' => 'never-return'], JSON_THROW_ON_ERROR), 'status' => 'dead_lettered',
        'attempts' => 12, 'manual_replay_count' => 3, 'received_at' => $observedAt->subHour(),
        'available_at' => $observedAt->subHour(), 'dead_lettered_at' => $observedAt,
        'created_at' => $observedAt->subHour(), 'updated_at' => $observedAt,
    ]);

    $metrics = app(OperationalMetricsSource::class)->snapshot($observedAt);

    expect($metrics)->toMatchArray([
        'billing_webhook_dead_letter_count' => 1, 'billing_outbox_dead_letter_count' => 0,
        'billing_webhook_stale_processing_count' => 0, 'billing_outbox_stale_processing_count' => 0,
        'billing_webhook_oldest_pending_age_minutes' => 0.0, 'billing_outbox_oldest_pending_age_minutes' => 20.0,
    ]);
    expect(json_encode($metrics, JSON_THROW_ON_ERROR))
        ->not->toContain('private-account')->not->toContain('never-return')->not->toContain($tenant->id);
});

it('treats an oversized backup success marker as unavailable without reading it unbounded', function () {
    $markerPath = tempnam(sys_get_temp_dir(), 'rb-backup-marker-large-');
    expect($markerPath)->toBeString();
    file_put_contents($markerPath, str_repeat('x', 4097));
    config(['operations.metrics.backup_success_marker_path' => $markerPath]);

    try {
        expect(app(OperationalMetricsSource::class)
            ->snapshot(CarbonImmutable::parse('2026-11-14T12:00:00Z'))['last_successful_backup_at'])
            ->toBeNull();
    } finally {
        @unlink($markerPath);
    }
});

<?php

declare(strict_types=1);

use App\Services\Operations\QueueJobMetricsTracker;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;

it('tracks Redis queue jobs only after their delay has elapsed and clears them when processing starts', function () {
    $queuedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $tracker = app(QueueJobMetricsTracker::class);

    event(new JobQueued('redis', 'default', 'redis-job-1', 'App\\Jobs\\ExampleJob', '{}', 300));

    // Event listeners use the application clock; exercise deterministic age math through the tracker as well.
    $tracker->recordQueued('redis', 'default', 'redis-job-2', 300, $queuedAt);

    expect($tracker->oldestQueuedJobAgeMinutes($queuedAt->addMinutes(4)))->toBeNull()
        ->and($tracker->oldestQueuedJobAgeMinutes($queuedAt->addMinutes(10)))->toBe(5.0)
        ->and(DB::table('operations_queue_job_samples')->count())->toBe(2);

    $tracker->markStarted('redis', 'redis-job-2');

    expect($tracker->oldestQueuedJobAgeMinutes($queuedAt->addMinutes(10)))->toBeNull()
        ->and(DB::table('operations_queue_job_samples')->where('job_id', 'redis-job-2')->exists())->toBeFalse();
});

it('resets the available time when a queue job is released for a delayed retry', function () {
    $queuedAt = CarbonImmutable::parse('2026-11-14T12:00:00Z');
    $tracker = app(QueueJobMetricsTracker::class);

    $tracker->recordQueued('redis', 'default', 'retry-job', 0, $queuedAt);
    $tracker->recordQueued('redis', 'default', 'retry-job', 600, $queuedAt->addMinute());

    expect($tracker->oldestQueuedJobAgeMinutes($queuedAt->addMinutes(9)))->toBeNull()
        ->and($tracker->oldestQueuedJobAgeMinutes($queuedAt->addMinutes(12)))->toBe(1.0)
        ->and(DB::table('operations_queue_job_samples')->count())->toBe(1);
});

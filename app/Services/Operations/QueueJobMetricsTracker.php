<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class QueueJobMetricsTracker
{
    public function recordQueued(
        string $connectionName,
        ?string $queueName,
        string|int|null $jobId,
        ?int $delaySeconds = null,
        ?CarbonImmutable $queuedAt = null,
    ): void {
        $jobId = $jobId !== null ? (string) $jobId : '';

        if ($jobId === '' || strlen($jobId) > 128) {
            return;
        }

        $queuedAt = ($queuedAt ?? CarbonImmutable::now())->utc();
        $now = CarbonImmutable::now()->utc();

        try {
            if (! Schema::hasTable('operations_queue_job_samples')) {
                return;
            }

            DB::table('operations_queue_job_samples')->updateOrInsert(
                [
                    'connection_name' => substr($connectionName, 0, 64),
                    'job_id' => $jobId,
                ],
                [
                    'queue_name' => substr($queueName ?: 'default', 0, 128),
                    'available_at' => $queuedAt->addSeconds(max(0, $delaySeconds ?? 0)),
                    'status' => 'queued',
                    'started_at' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Operational queue metric could not be recorded.', [
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function markStarted(string $connectionName, string|int|null $jobId): void
    {
        $jobId = $jobId !== null ? (string) $jobId : '';

        if ($jobId === '') {
            return;
        }

        try {
            if (! Schema::hasTable('operations_queue_job_samples')) {
                return;
            }

            DB::table('operations_queue_job_samples')
                ->where('connection_name', substr($connectionName, 0, 64))
                ->where('job_id', $jobId)
                ->update([
                    'status' => 'processing',
                    'started_at' => CarbonImmutable::now()->utc(),
                    'updated_at' => CarbonImmutable::now()->utc(),
                ]);
        } catch (Throwable $exception) {
            Log::warning('Operational queue metric could not be cleared when processing started.', [
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function markFinished(string $connectionName, string|int|null $jobId): void
    {
        $jobId = $jobId !== null ? (string) $jobId : '';

        if ($jobId === '') {
            return;
        }

        try {
            if (! Schema::hasTable('operations_queue_job_samples')) {
                return;
            }

            DB::table('operations_queue_job_samples')
                ->where('connection_name', substr($connectionName, 0, 64))
                ->where('job_id', $jobId)
                ->delete();
        } catch (Throwable $exception) {
            Log::warning('Operational queue metric could not be cleared when processing finished.', [
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function oldestProcessingJobAgeMinutes(?CarbonImmutable $observedAt = null): ?float
    {
        if (! Schema::hasTable('operations_queue_job_samples')) {
            return null;
        }

        try {
            $observedAt = ($observedAt ?? CarbonImmutable::now())->utc();
            $oldestStartedAt = DB::table('operations_queue_job_samples')
                ->where('status', 'processing')
                ->whereNotNull('started_at')
                ->min('started_at');

            if (! is_string($oldestStartedAt)) {
                return null;
            }

            $ageSeconds = $observedAt->getTimestamp()
                - CarbonImmutable::parse($oldestStartedAt)->utc()->getTimestamp();

            return $ageSeconds < 0 ? null : $ageSeconds / 60;
        } catch (Throwable) {
            return null;
        }
    }

    public function oldestQueuedJobAgeMinutes(?CarbonImmutable $observedAt = null): ?float
    {
        if (! Schema::hasTable('operations_queue_job_samples')) {
            return null;
        }

        try {
            $observedAt = ($observedAt ?? CarbonImmutable::now())->utc();
            $oldestAvailableAt = DB::table('operations_queue_job_samples')
                ->where('status', 'queued')
                ->where('available_at', '<=', $observedAt)
                ->min('available_at');

            if (! is_string($oldestAvailableAt)) {
                return null;
            }

            $ageSeconds = $observedAt->getTimestamp()
                - CarbonImmutable::parse($oldestAvailableAt)->utc()->getTimestamp();

            return $ageSeconds < 0 ? null : $ageSeconds / 60;
        } catch (Throwable) {
            return null;
        }
    }
}

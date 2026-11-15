<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Throwable;

final class AlertThresholdPolicy
{
    /**
     * Evaluate already-aggregated operational metrics against the approved thresholds.
     *
     * @param  array<string, mixed>  $metrics
     * @return list<array{code: string, severity: string, observed: int|float|null, threshold: int|float, window_minutes?: int}>
     */
    public function evaluate(array $metrics, ?CarbonImmutable $observedAt = null): array
    {
        $observedAt = ($observedAt ?? CarbonImmutable::now())->utc();
        $alerts = [];

        $errorRate = $this->numericMetric($metrics, 'error_rate_percent');
        $errorRateThreshold = (float) config('operations.monitoring.error_rate.threshold_percent', 2);
        $errorWindow = (int) config('operations.monitoring.error_rate.window_minutes', 5);

        if ($errorRate !== null && $errorRate > $errorRateThreshold) {
            $alerts[] = [
                'code' => 'error_rate_exceeded',
                'severity' => 'alert',
                'observed' => $errorRate,
                'threshold' => $errorRateThreshold,
                'window_minutes' => $errorWindow,
            ];
        }

        $apiP95 = $this->numericMetric($metrics, 'api_p95_latency_ms');
        $apiP95Threshold = (int) config('operations.monitoring.api_p95_latency_ms', 1500);

        if ($apiP95 !== null && $apiP95 > $apiP95Threshold) {
            $alerts[] = [
                'code' => 'api_p95_latency_exceeded',
                'severity' => 'alert',
                'observed' => $apiP95,
                'threshold' => $apiP95Threshold,
            ];
        }

        $backupMaxAgeHours = (int) config('operations.monitoring.backup_max_age_hours', 26);
        $lastSuccessfulBackupAt = $metrics['last_successful_backup_at'] ?? null;

        if ($lastSuccessfulBackupAt === null || $lastSuccessfulBackupAt === '') {
            $alerts[] = [
                'code' => 'successful_backup_missing',
                'severity' => 'alert',
                'observed' => null,
                'threshold' => $backupMaxAgeHours,
            ];
        } else {
            try {
                $backupAt = CarbonImmutable::parse($lastSuccessfulBackupAt)->utc();
                $backupAgeSeconds = $backupAt->diffInSeconds($observedAt, false);
                $backupAgeHours = $backupAgeSeconds / 3600;

                if ($backupAgeSeconds < 0) {
                    $alerts[] = [
                        'code' => 'successful_backup_timestamp_invalid',
                        'severity' => 'alert',
                        'observed' => $backupAgeHours,
                        'threshold' => $backupMaxAgeHours,
                    ];
                } elseif ($backupAgeHours >= $backupMaxAgeHours) {
                    $alerts[] = [
                        'code' => 'successful_backup_stale',
                        'severity' => 'alert',
                        'observed' => $backupAgeHours,
                        'threshold' => $backupMaxAgeHours,
                    ];
                }
            } catch (Throwable) {
                $alerts[] = [
                    'code' => 'successful_backup_timestamp_invalid',
                    'severity' => 'alert',
                    'observed' => null,
                    'threshold' => $backupMaxAgeHours,
                ];
            }
        }

        $diskUsage = $this->numericMetric($metrics, 'disk_usage_percent');
        $diskWarningThreshold = (int) config('operations.monitoring.disk.warning_percent', 80);
        $diskCriticalThreshold = (int) config('operations.monitoring.disk.critical_percent', 90);

        if ($diskUsage !== null && $diskUsage >= $diskCriticalThreshold) {
            $alerts[] = [
                'code' => 'disk_usage_critical',
                'severity' => 'critical',
                'observed' => $diskUsage,
                'threshold' => $diskCriticalThreshold,
            ];
        } elseif ($diskUsage !== null && $diskUsage >= $diskWarningThreshold) {
            $alerts[] = [
                'code' => 'disk_usage_warning',
                'severity' => 'warning',
                'observed' => $diskUsage,
                'threshold' => $diskWarningThreshold,
            ];
        }

        $oldestQueueJobAge = $this->numericMetric($metrics, 'oldest_queue_job_age_minutes');
        $queueAgeThreshold = (int) config('operations.monitoring.queue_job_max_age_minutes', 15);

        if ($oldestQueueJobAge !== null && $oldestQueueJobAge > $queueAgeThreshold) {
            $alerts[] = [
                'code' => 'queue_job_age_exceeded',
                'severity' => 'alert',
                'observed' => $oldestQueueJobAge,
                'threshold' => $queueAgeThreshold,
            ];
        }


        $billingBacklogMinutes = (int) config('operations.monitoring.queue_job_max_age_minutes', 15);

        foreach (['webhook', 'outbox'] as $queue) {
            $deadLetters = $this->numericMetric($metrics, "billing_{$queue}_dead_letter_count");
            if ($deadLetters !== null && $deadLetters > 0) {
                $alerts[] = ['code' => "billing_{$queue}_dead_lettered", 'severity' => 'alert', 'observed' => $deadLetters, 'threshold' => 0];
            }

            $staleProcessing = $this->numericMetric($metrics, "billing_{$queue}_stale_processing_count");
            if ($staleProcessing !== null && $staleProcessing > 0) {
                $alerts[] = ['code' => "billing_{$queue}_processing_stale", 'severity' => 'alert', 'observed' => $staleProcessing, 'threshold' => 0];
            }

            $oldestPending = $this->numericMetric($metrics, "billing_{$queue}_oldest_pending_age_minutes");
            if ($oldestPending !== null && $oldestPending > $billingBacklogMinutes) {
                $alerts[] = ['code' => "billing_{$queue}_backlog_age_exceeded", 'severity' => 'alert', 'observed' => $oldestPending, 'threshold' => $billingBacklogMinutes];
            }
        }

        return $alerts;
    }

    /**
     * Return alert codes whose state can be safely resolved from this snapshot.
     * Missing metrics are deliberately omitted so telemetry gaps cannot clear an
     * alert that has not been disproved by an observed healthy value.
     *
     * @param  array<string, mixed>  $metrics
     * @return list<string>
     */
    public function evaluatedCodes(array $metrics): array
    {
        $codes = [];

        if ($this->numericMetric($metrics, 'error_rate_percent') !== null) {
            $codes[] = 'error_rate_exceeded';
        }

        if ($this->numericMetric($metrics, 'api_p95_latency_ms') !== null) {
            $codes[] = 'api_p95_latency_exceeded';
        }

        // Backup absence, malformed timestamps and staleness are themselves evaluated states.
        $codes[] = 'successful_backup_missing';
        $codes[] = 'successful_backup_stale';
        $codes[] = 'successful_backup_timestamp_invalid';

        if ($this->numericMetric($metrics, 'disk_usage_percent') !== null) {
            $codes[] = 'disk_usage_warning';
            $codes[] = 'disk_usage_critical';
        }

        if ($this->numericMetric($metrics, 'oldest_queue_job_age_minutes') !== null) {
            $codes[] = 'queue_job_age_exceeded';
        }


        foreach (['webhook', 'outbox'] as $queue) {
            foreach (['dead_letter_count' => 'dead_lettered', 'stale_processing_count' => 'processing_stale', 'oldest_pending_age_minutes' => 'backlog_age_exceeded'] as $metric => $suffix) {
                if ($this->numericMetric($metrics, "billing_{$queue}_{$metric}") !== null) {
                    $codes[] = "billing_{$queue}_{$suffix}";
                }
            }
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function numericMetric(array $metrics, string $key): ?float
    {
        $value = $metrics[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}

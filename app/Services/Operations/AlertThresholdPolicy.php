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

        return $alerts;
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

<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\Tenancy\TenantBillingOperationsConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class OperationalMetricsSource
{
    private const MAX_BACKUP_MARKER_BYTES = 4096;

    public function __construct(private readonly QueueJobMetricsTracker $queueMetrics) {}

    /**
     * Return normalized metrics for AlertThresholdPolicy without exposing request payloads.
     *
     * @return array{
     *   error_rate_percent: float|null,
     *   api_p95_latency_ms: float|null,
     *   last_successful_backup_at: string|null,
     *   disk_usage_percent: float|null,
     *   oldest_queue_job_age_minutes: float|null,
     *   oldest_processing_job_age_minutes: float|null,
     *   billing_webhook_dead_letter_count: int|null,
     *   billing_outbox_dead_letter_count: int|null,
     *   billing_webhook_stale_processing_count: int|null,
     *   billing_outbox_stale_processing_count: int|null,
     *   billing_webhook_oldest_pending_age_minutes: float|null,
     *   billing_outbox_oldest_pending_age_minutes: float|null
     * }
     */
    public function snapshot(?CarbonImmutable $observedAt = null): array
    {
        $observedAt = ($observedAt ?? CarbonImmutable::now())->utc();
        $windowMinutes = max(1, (int) config('operations.monitoring.error_rate.window_minutes', 5));
        $cutoff = $observedAt->subMinutes($windowMinutes);
        $sampleCount = 0;
        $failedCount = 0;
        $p95Latency = null;

        if (Schema::hasTable('operations_metric_samples')) {
            $samples = DB::table('operations_metric_samples')
                ->where('created_at', '>=', $cutoff);

            $sampleCount = (clone $samples)->count();
            $failedCount = (clone $samples)->where('status_code', '>=', 500)->count();

            if ($sampleCount > 0) {
                $p95Index = max(0, (int) ceil($sampleCount * 0.95) - 1);
                $p95Latency = (clone $samples)
                    ->orderBy('duration_ms')
                    ->offset($p95Index)
                    ->value('duration_ms');
            }
        }

        return [
            'error_rate_percent' => $sampleCount > 0 ? ($failedCount / $sampleCount) * 100 : null,
            'api_p95_latency_ms' => $p95Latency !== null ? (float) $p95Latency : null,
            'last_successful_backup_at' => $this->lastSuccessfulBackupAt($observedAt),
            'disk_usage_percent' => $this->diskUsagePercent(),
            'oldest_queue_job_age_minutes' => $this->oldestQueueJobAgeMinutes($observedAt),
            'oldest_processing_job_age_minutes' => $this->queueMetrics->oldestProcessingJobAgeMinutes($observedAt),
            ...$this->billingQueueMetrics($observedAt),
        ];
    }

    public function pruneSamples(?CarbonImmutable $observedAt = null): int
    {
        if (! Schema::hasTable('operations_metric_samples')) {
            return 0;
        }

        return DB::table('operations_metric_samples')
            ->where('created_at', '<', ($observedAt ?? CarbonImmutable::now())->utc()->subDay())
            ->delete();
    }

    /**
     * Aggregate billing queue health under the separately guarded operations role.
     * Null metrics preserve active alerts when telemetry or role configuration is unavailable.
     *
     * @return array{
     *   billing_webhook_dead_letter_count: int|null,
     *   billing_outbox_dead_letter_count: int|null,
     *   billing_webhook_stale_processing_count: int|null,
     *   billing_outbox_stale_processing_count: int|null,
     *   billing_webhook_oldest_pending_age_minutes: float|null,
     *   billing_outbox_oldest_pending_age_minutes: float|null
     * }
     */
    private function billingQueueMetrics(CarbonImmutable $observedAt): array
    {
        $empty = [
            'billing_webhook_dead_letter_count' => null,
            'billing_outbox_dead_letter_count' => null,
            'billing_webhook_stale_processing_count' => null,
            'billing_outbox_stale_processing_count' => null,
            'billing_webhook_oldest_pending_age_minutes' => null,
            'billing_outbox_oldest_pending_age_minutes' => null,
        ];

        try {
            return app(TenantBillingOperationsConnection::class)->run(function () use ($observedAt, $empty): array {
                $metrics = $empty;
                $staleBefore = $observedAt->subMinutes(max(1, (int) config('operations.monitoring.billing_stale_processing_minutes', 5)));

                foreach (['webhook' => 'tenant_billing_webhook_events', 'outbox' => 'tenant_billing_outbox'] as $kind => $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    $metrics["billing_{$kind}_dead_letter_count"] = DB::table($table)->where('status', 'dead_lettered')->count();
                    $metrics["billing_{$kind}_stale_processing_count"] = DB::table($table)
                        ->where('status', 'processing')->whereNotNull('locked_at')->where('locked_at', '<=', $staleBefore)->count();
                    $oldestDue = DB::table($table)->where('status', 'pending')
                        ->where('available_at', '<=', $observedAt)->min('available_at');
                    $metrics["billing_{$kind}_oldest_pending_age_minutes"] = is_string($oldestDue)
                        ? max(0, ($observedAt->getTimestamp() - CarbonImmutable::parse($oldestDue, 'UTC')->getTimestamp()) / 60)
                        : 0.0;
                }

                return $metrics;
            });
        } catch (Throwable) {
            return $empty;
        }
    }

    private function lastSuccessfulBackupAt(CarbonImmutable $observedAt): ?string
    {
        $path = config('operations.metrics.backup_success_marker_path');

        if (! is_string($path) || trim($path) === '' || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_BACKUP_MARKER_BYTES + 1);
        if (! is_string($contents) || strlen($contents) > self::MAX_BACKUP_MARKER_BYTES) {
            return null;
        }

        try {
            $marker = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($marker)
                || ! is_string($marker['backup_id'] ?? null)
                || ! Str::isUuid($marker['backup_id'])
                || ! is_string($marker['created_at'] ?? null)
                || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$/D', $marker['created_at']) !== 1) {
                return null;
            }

            $createdAt = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $marker['created_at']);
            if ($createdAt === null
                || $createdAt->toIso8601String() !== $marker['created_at']
                || $createdAt->greaterThan($observedAt->addMinutes(5))) {
                return null;
            }

            return $createdAt->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function diskUsagePercent(): ?float
    {
        $path = config('operations.metrics.disk_path', storage_path());

        if (! is_string($path) || ! is_dir($path)) {
            return null;
        }

        $totalBytes = @disk_total_space($path);
        $freeBytes = @disk_free_space($path);

        if ($totalBytes === false || $freeBytes === false || $totalBytes <= 0 || $freeBytes < 0) {
            return null;
        }

        return (($totalBytes - $freeBytes) / $totalBytes) * 100;
    }

    private function oldestQueueJobAgeMinutes(CarbonImmutable $observedAt): ?float
    {
        $trackedAge = $this->queueMetrics->oldestQueuedJobAgeMinutes($observedAt);
        $databaseAge = $this->oldestDatabaseQueueJobAgeMinutes($observedAt);

        if ($trackedAge === null) {
            return $databaseAge;
        }

        if ($databaseAge === null) {
            return $trackedAge;
        }

        return max($trackedAge, $databaseAge);
    }

    private function oldestDatabaseQueueJobAgeMinutes(CarbonImmutable $observedAt): ?float
    {
        $defaultConnection = (string) config('queue.default');
        $connectionName = (string) config('queue.connections.'.$defaultConnection.'.connection');
        $connectionName = $connectionName !== '' ? $connectionName : (string) config('database.default');
        $table = (string) config('queue.connections.'.$defaultConnection.'.table', 'jobs');

        if (config('queue.connections.'.$defaultConnection.'.driver') !== 'database') {
            return null;
        }

        try {
            if (! Schema::connection($connectionName)->hasTable($table)) {
                return null;
            }

            $oldestCreatedAt = DB::connection($connectionName)->table($table)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $observedAt->getTimestamp())
                ->min('created_at');

            if (! is_numeric($oldestCreatedAt)) {
                return null;
            }

            $ageSeconds = $observedAt->getTimestamp() - (int) $oldestCreatedAt;

            return $ageSeconds < 0 ? null : $ageSeconds / 60;
        } catch (Throwable) {
            return null;
        }
    }
}

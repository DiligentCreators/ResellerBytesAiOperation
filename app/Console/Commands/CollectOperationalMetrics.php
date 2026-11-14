<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\OperationalMetricsSource;
use Illuminate\Console\Command;
use JsonException;

final class CollectOperationalMetrics extends Command
{
    protected $signature = 'operations:metrics';

    protected $description = 'Collect a redacted snapshot of available operational metrics';

    /**
     * @throws JsonException
     */
    public function handle(OperationalMetricsSource $source): int
    {
        $observedAt = now()->toImmutable()->utc();
        $metrics = $source->snapshot($observedAt);
        $prunedSamples = $source->pruneSamples($observedAt);

        $this->line(json_encode([
            'schema_version' => 1,
            'observed_at' => $observedAt->toIso8601String(),
            'metrics' => $metrics,
            'pruned_metric_samples' => $prunedSamples,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

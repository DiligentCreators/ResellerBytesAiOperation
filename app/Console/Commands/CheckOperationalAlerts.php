<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\AlertStateService;
use App\Services\Operations\AlertThresholdPolicy;
use App\Services\Operations\OperationalMetricsSource;
use Illuminate\Console\Command;
use JsonException;

final class CheckOperationalAlerts extends Command
{
    protected $signature = 'operations:check-alerts';

    protected $description = 'Evaluate operational metrics and deliver deduplicated alerts';

    /**
     * @throws JsonException
     */
    public function handle(
        OperationalMetricsSource $source,
        AlertThresholdPolicy $policy,
        AlertStateService $states,
    ): int {
        $observedAt = now()->toImmutable()->utc();
        $metrics = $source->snapshot($observedAt);
        $alerts = $policy->evaluate($metrics, $observedAt);
        $result = $states->process($alerts, $observedAt, $policy->evaluatedCodes($metrics));

        $this->line(json_encode([
            'schema_version' => 1,
            'observed_at' => $observedAt->toIso8601String(),
            ...$result,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['notifications_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

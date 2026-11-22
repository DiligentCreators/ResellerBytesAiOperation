<?php

namespace App\Services\Health;

use App\Models\WhmcsIntegration;

class HealthScoreCalculator
{
    /**
     * Compute a 0–100 health score from open findings and heartbeat state.
     *
     * Score = max(0, 100 − Σ weight(severity) × log2(1 + affected_count)).
     * When heartbeat is down, the score is capped at config('health.heartbeat_down_cap').
     *
     * @param  list<array{severity: string, affected_count?: int|null}>  $openFindings
     * @param  'unknown'|'ok'|'stale'|'down'|string|null  $heartbeatState
     */
    public function calculate(array $openFindings, ?string $heartbeatState = null): int
    {
        $weights = (array) config('health.weights', []);
        $criticalWeight = (float) ($weights['critical'] ?? 25);
        $warningWeight = (float) ($weights['warning'] ?? 8);
        $infoWeight = (float) ($weights['info'] ?? 2);

        $deduction = 0.0;
        foreach ($openFindings as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? 'info'));
            $weight = match ($severity) {
                'critical' => $criticalWeight,
                'warning' => $warningWeight,
                default => $infoWeight,
            };

            $affected = max(0, (int) ($finding['affected_count'] ?? 0));
            $deduction += $weight * log(1 + $affected, 2);
        }

        $score = (int) max(0, (int) floor(100 - $deduction));

        if ($heartbeatState === WhmcsIntegration::HEARTBEAT_STATE_DOWN) {
            $cap = (int) config('health.heartbeat_down_cap', 40);
            $score = min($score, max(0, $cap));
        }

        return min(100, $score);
    }
}

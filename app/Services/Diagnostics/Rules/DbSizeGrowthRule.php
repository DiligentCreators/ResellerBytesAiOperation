<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class DbSizeGrowthRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'db.size_growth';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $host = $payload['host'] ?? null;
        if (! is_array($host) || ! is_numeric($host['db_size_mb'] ?? null)) {
            return null;
        }

        $sizeMb = (float) $host['db_size_mb'];
        $threshold = $this->floatSetting('monitoring.db_size_warning_mb', 5120.0);
        if ($sizeMb < $threshold) {
            return null;
        }

        $topTables = [];
        if (is_array($host['db_top_tables'] ?? null)) {
            foreach (array_slice($host['db_top_tables'], 0, 5) as $row) {
                if (! is_array($row) || ! is_string($row['name'] ?? null)) {
                    continue;
                }
                $topTables[] = [
                    'name' => substr($row['name'], 0, 200),
                    'size_mb' => is_numeric($row['size_mb'] ?? null) ? (float) $row['size_mb'] : null,
                ];
            }
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'host.db_size_mb' => $sizeMb,
                'monitoring.db_size_warning_mb' => $threshold,
                'host.db_top_tables' => $topTables,
            ],
        );
    }
}

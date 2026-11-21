<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class CronOverlapDuplicatesRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'cron.overlap_duplicates';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $cron = $this->whmcsBlock($payload, 'cron');
        if ($cron === null) {
            return null;
        }

        $starts = $cron['daily_starts_24h'] ?? null;
        $completions = $cron['daily_completions_24h'] ?? null;
        if (! is_numeric($starts) || ! is_numeric($completions)) {
            return null;
        }

        $starts = (int) $starts;
        $completions = (int) $completions;
        $excess = $this->intSetting('monitoring.cron_overlap_start_excess', 2);

        if ($starts < 2 || ($starts - $completions) < $excess) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $starts - $completions,
            [
                'whmcs.cron.daily_starts_24h' => $starts,
                'whmcs.cron.daily_completions_24h' => $completions,
            ],
        );
    }
}

<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class CronDailyNotCompletingRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'cron.daily_not_completing';
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
        if ($starts < 1 || $completions >= $starts) {
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

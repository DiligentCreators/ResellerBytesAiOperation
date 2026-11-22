<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class CronDailyMissedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'cron.daily_missed';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $cron = $this->whmcsBlock($payload, 'cron');
        if ($cron === null || ! array_key_exists('last_daily_run_at', $cron)) {
            return null;
        }

        $lastDaily = $this->parseUtc(is_string($cron['last_daily_run_at']) ? $cron['last_daily_run_at'] : null);
        if ($lastDaily === null) {
            return null;
        }

        $missedHours = $this->intSetting('monitoring.daily_cron_missed_hours', 36);
        if ($lastDaily->greaterThan(now('UTC')->subHours($missedHours))) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'whmcs.cron.last_daily_run_at' => $lastDaily->toIso8601ZuluString(),
                'monitoring.daily_cron_missed_hours' => $missedHours,
            ],
        );
    }
}

<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;
use Illuminate\Support\Carbon;

class CronNotRunningRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'cron.not_running';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $staleMinutes = $this->intSetting('monitoring.cron_stale_minutes', 30);
        $lastCronAt = $integration->getAttribute('last_cron_at');

        if ($lastCronAt !== null) {
            try {
                $last = Carbon::parse($lastCronAt)->utc();
            } catch (\Throwable) {
                return null;
            }

            if ($last->greaterThan(now('UTC')->subMinutes($staleMinutes))) {
                return null;
            }

            return new DetectedIssue(
                $this->key(),
                $this->catalogSeverity($this->key()),
                1,
                [
                    'integration.last_cron_at' => $last->toIso8601ZuluString(),
                    'monitoring.cron_stale_minutes' => $staleMinutes,
                ],
            );
        }

        $cron = $this->whmcsBlock($payload, 'cron');
        if ($cron === null) {
            return null;
        }

        $starts = $cron['daily_starts_24h'] ?? null;
        $completions = $cron['daily_completions_24h'] ?? null;
        if (! is_numeric($starts) || ! is_numeric($completions)) {
            return null;
        }

        if ((int) $starts > 0 || (int) $completions > 0) {
            return null;
        }

        $lastDaily = $this->parseUtc(is_string($cron['last_daily_run_at'] ?? null) ? $cron['last_daily_run_at'] : null);
        if ($lastDaily !== null && $lastDaily->greaterThan(now('UTC')->subMinutes($staleMinutes))) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'whmcs.cron.daily_starts_24h' => (int) $starts,
                'whmcs.cron.daily_completions_24h' => (int) $completions,
                'whmcs.cron.last_daily_run_at' => $lastDaily?->toIso8601ZuluString(),
            ],
        );
    }
}

<?php

namespace App\Services\Monitoring;

use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use App\Services\PlatformSettings;
use Illuminate\Support\Carbon;

class AutoCollectDueEvaluator
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function isDue(WhmcsIntegration $integration): bool
    {
        if (! (bool) $integration->getAttribute('auto_collect_enabled')) {
            return false;
        }

        $intervalMinutes = $this->intSetting('monitoring.auto_collect_interval_minutes', 60);
        $maxPerDay = $this->intSetting('monitoring.max_snapshots_per_install_per_day', 48);

        $lastAutoCollect = $integration->getAttribute('last_auto_collect_at');
        if ($lastAutoCollect !== null) {
            try {
                $last = Carbon::parse($lastAutoCollect)->utc();
            } catch (\Throwable) {
                $last = null;
            }

            if ($last !== null && $last->greaterThan(now('UTC')->subMinutes($intervalMinutes))) {
                return false;
            }
        }

        $dayStart = now('UTC')->startOfDay();
        $todayCount = DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->where('received_at', '>=', $dayStart)
            ->count();

        return $todayCount < $maxPerDay;
    }

    private function intSetting(string $key, int $default): int
    {
        try {
            $value = $this->settings->get($key);
        } catch (\Throwable) {
            $value = config($key, $default);
        }

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}

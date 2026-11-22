<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;
use Illuminate\Support\Carbon;

class HeartbeatMissedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'heartbeat.missed';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $lastHeartbeat = $integration->getAttribute('last_heartbeat_at');
        if ($lastHeartbeat === null) {
            return null;
        }

        try {
            $last = Carbon::parse($lastHeartbeat)->utc();
        } catch (\Throwable) {
            return null;
        }

        $staleMinutes = $this->intSetting('monitoring.heartbeat_stale_minutes', 30);
        if ($last->greaterThan(now('UTC')->subMinutes($staleMinutes))) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'integration.last_heartbeat_at' => $last->toIso8601ZuluString(),
                'monitoring.heartbeat_stale_minutes' => $staleMinutes,
            ],
        );
    }
}

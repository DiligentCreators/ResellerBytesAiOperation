<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class StorageDiskLowRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'storage.disk_low';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $host = $payload['host'] ?? null;
        if (! is_array($host) || ! is_numeric($host['disk_free_percent'] ?? null)) {
            return null;
        }

        $free = (float) $host['disk_free_percent'];
        $threshold = $this->floatSetting('monitoring.disk_free_warning_percent', 15.0);
        if ($free > $threshold) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'host.disk_free_percent' => $free,
                'monitoring.disk_free_warning_percent' => $threshold,
                'host.disk_free_bytes' => is_numeric($host['disk_free_bytes'] ?? null) ? (int) $host['disk_free_bytes'] : null,
            ],
        );
    }
}

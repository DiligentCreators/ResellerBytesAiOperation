<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class AddonOutdatedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'addon.outdated';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $current = $integration->addon_version;
        if (! is_string($current) || trim($current) === '') {
            return null;
        }

        $latest = null;
        try {
            $latest = $this->settings->get('monitoring.addon_latest_version');
        } catch (\Throwable) {
            $latest = config('monitoring.addon_latest_version');
        }

        if (! is_string($latest) || trim($latest) === '') {
            return null;
        }

        if ($this->compareVersions($current, $latest) >= 0) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'integration.addon_version' => $current,
                'monitoring.addon_latest_version' => $latest,
            ],
        );
    }
}

<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class WhmcsVersionOutdatedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'whmcs.version_outdated';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $version = $payload['system']['whmcs_version'] ?? null;
        if (! is_string($version) || $version === '' || strtolower($version) === 'unknown') {
            return null;
        }

        $line = $this->majorMinor($version);
        if ($line === null) {
            return null;
        }

        $baselines = $this->arraySetting('monitoring.whmcs_security_baselines');
        $baseline = $baselines[$line] ?? null;
        if (! is_string($baseline) || $baseline === '') {
            return null;
        }

        // Same signal as patch lag for known lines; keep a dedicated product key for UX.
        if ($this->compareVersions($version, $baseline) >= 0) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'system.whmcs_version' => $version,
                'monitoring.whmcs_security_baselines.'.$line => $baseline,
            ],
        );
    }
}

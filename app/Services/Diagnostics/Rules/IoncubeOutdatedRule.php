<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class IoncubeOutdatedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'ioncube.outdated';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $whmcsVersion = $payload['system']['whmcs_version'] ?? null;
        $ioncube = $payload['php']['ioncube_version'] ?? null;
        if (! is_string($whmcsVersion) || ! is_string($ioncube)) {
            return null;
        }
        if ($whmcsVersion === '' || strtolower($whmcsVersion) === 'unknown' || trim($ioncube) === '') {
            return null;
        }

        $line = $this->majorMinor($whmcsVersion);
        if ($line === null) {
            return null;
        }

        $minimums = $this->arraySetting('monitoring.ioncube_minimum_versions');
        $minimum = $minimums[$line] ?? null;
        if (! is_numeric($minimum)) {
            return null;
        }

        $major = (int) explode('.', $ioncube)[0];
        if ($major < 1 || $major >= (int) $minimum) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'system.whmcs_version' => $whmcsVersion,
                'php.ioncube_version' => $ioncube,
                'monitoring.ioncube_minimum_versions.'.$line => (int) $minimum,
            ],
        );
    }
}

<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class PhpVersionUnsupportedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'php.version_unsupported';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $whmcsVersion = $payload['system']['whmcs_version'] ?? null;
        $phpVersion = $payload['system']['web_php_version'] ?? null;
        if (! is_string($whmcsVersion) || ! is_string($phpVersion)) {
            return null;
        }
        if ($whmcsVersion === '' || strtolower($whmcsVersion) === 'unknown' || $phpVersion === '') {
            return null;
        }

        $line = $this->majorMinor($whmcsVersion);
        if ($line === null) {
            return null;
        }

        $matrix = $this->arraySetting('monitoring.php_support_matrix');
        $range = $matrix[$line] ?? null;
        if (! is_array($range) || ! is_string($range['min'] ?? null) || ! is_string($range['max'] ?? null)) {
            return null;
        }

        $tooLow = $this->compareVersions($phpVersion, $range['min']) < 0;
        $tooHigh = $this->compareVersions($phpVersion, $range['max']) > 0;
        if (! $tooLow && ! $tooHigh) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'system.whmcs_version' => $whmcsVersion,
                'system.web_php_version' => $phpVersion,
                'monitoring.php_support_matrix.'.$line.'.min' => $range['min'],
                'monitoring.php_support_matrix.'.$line.'.max' => $range['max'],
            ],
        );
    }
}

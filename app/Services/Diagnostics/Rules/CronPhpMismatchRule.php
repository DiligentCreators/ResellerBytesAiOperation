<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class CronPhpMismatchRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'cron.php_mismatch';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $web = $payload['system']['web_php_version'] ?? null;
        if (! is_string($web) || $web === '') {
            return null;
        }

        $cli = $integration->getAttribute('cron_php_version');
        if (! is_string($cli) || $cli === '') {
            return null;
        }

        if ($this->compareVersions($web, $cli) === 0) {
            return null;
        }

        // Compare major.minor only to avoid patch-noise false positives when both report differently.
        $webMm = $this->majorMinor($web);
        $cliMm = $this->majorMinor($cli);
        if ($webMm !== null && $cliMm !== null && $webMm === $cliMm) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'system.web_php_version' => $web,
                'integration.cron_php_version' => $cli,
            ],
        );
    }
}

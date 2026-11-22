<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class PhpVersionEolRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'php.version_eol';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $phpVersion = $payload['system']['web_php_version'] ?? null;
        if (! is_string($phpVersion) || $phpVersion === '' || strtolower($phpVersion) === 'unknown') {
            return null;
        }

        $branch = $this->majorMinor($phpVersion);
        if ($branch === null) {
            return null;
        }

        $eolBranches = $this->normalizedEolBranches();
        if (! in_array($branch, $eolBranches, true)) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'system.web_php_version' => $phpVersion,
                'monitoring.php_eol_branches' => $eolBranches,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function normalizedEolBranches(): array
    {
        $raw = $this->arraySetting('monitoring.php_eol_branches');
        $branches = [];
        foreach ($raw as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $branch = $this->majorMinor((string) $value);
            if ($branch !== null) {
                $branches[] = $branch;
            }
        }

        return array_values(array_unique($branches));
    }
}

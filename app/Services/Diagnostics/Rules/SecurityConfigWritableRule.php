<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class SecurityConfigWritableRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'security.config_writable';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        if (! array_key_exists('configuration_file_writable', $payload['system'] ?? [])) {
            return null;
        }

        $writable = $payload['system']['configuration_file_writable'];
        if ($writable !== true) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            ['system.configuration_file_writable' => true],
        );
    }
}

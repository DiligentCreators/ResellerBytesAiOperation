<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class PhpDisplayErrorsOnRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'php.display_errors_on';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        if (! array_key_exists('error_display_disabled', $payload['system'] ?? [])) {
            return null;
        }

        $disabled = $payload['system']['error_display_disabled'];
        if ($disabled !== false) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            ['system.error_display_disabled' => false],
        );
    }
}

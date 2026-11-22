<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class PhpExtensionMissingRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'php.extension_missing';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $extensions = $payload['php']['extensions'] ?? null;
        if (! is_array($extensions) || $extensions === []) {
            return null;
        }

        $required = config('monitoring.required_php_extensions', []);
        if (! is_array($required) || $required === []) {
            return null;
        }

        $missing = [];
        foreach ($required as $name) {
            if (! is_string($name) || ! array_key_exists($name, $extensions)) {
                continue;
            }
            if ($extensions[$name] === false) {
                $missing[] = $name;
            }
        }

        if ($missing === []) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            count($missing),
            ['php.extensions.missing' => $missing],
        );
    }
}

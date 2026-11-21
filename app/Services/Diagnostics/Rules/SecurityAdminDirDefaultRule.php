<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class SecurityAdminDirDefaultRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'security.admin_dir_default';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $security = $payload['security'] ?? null;
        if (! is_array($security) || ! array_key_exists('admin_dir_default', $security)) {
            return null;
        }

        if ($security['admin_dir_default'] !== true) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            ['security.admin_dir_default' => true],
        );
    }
}

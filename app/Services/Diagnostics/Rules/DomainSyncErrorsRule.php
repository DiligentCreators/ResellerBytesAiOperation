<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class DomainSyncErrorsRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'domain.sync_errors';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $domains = $this->whmcsBlock($payload, 'domains');
        if ($domains === null || ! is_numeric($domains['sync_errors_7d'] ?? null)) {
            return null;
        }

        $count = (int) $domains['sync_errors_7d'];
        $threshold = $this->intSetting('monitoring.domain_sync_error_threshold_7d', 1);
        if ($count < $threshold) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $count,
            ['whmcs.domains.sync_errors_7d' => $count],
        );
    }
}

<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class DomainActiveExpiredRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'domain.active_expired';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $domains = $this->whmcsBlock($payload, 'domains');
        if ($domains === null || ! is_array($domains['active_expired'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($domains['active_expired'], 'domain_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.domains.active_expired.count' => $aggregate['count'],
                'whmcs.domains.active_expired.domain_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

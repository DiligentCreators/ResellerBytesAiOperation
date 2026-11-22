<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class DomainBadExpiryRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'domain.bad_expiry';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $domains = $this->whmcsBlock($payload, 'domains');
        if ($domains === null || ! is_array($domains['bad_expiry'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($domains['bad_expiry'], 'domain_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.domains.bad_expiry.count' => $aggregate['count'],
                'whmcs.domains.bad_expiry.domain_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

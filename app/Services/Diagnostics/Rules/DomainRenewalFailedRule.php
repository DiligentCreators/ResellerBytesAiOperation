<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class DomainRenewalFailedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'domain.renewal_failed';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $domains = $this->whmcsBlock($payload, 'domains');
        if ($domains === null || ! is_array($domains['renewal_failed_7d'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($domains['renewal_failed_7d'], 'domain_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.domains.renewal_failed_7d.count' => $aggregate['count'],
                'whmcs.domains.renewal_failed_7d.domain_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

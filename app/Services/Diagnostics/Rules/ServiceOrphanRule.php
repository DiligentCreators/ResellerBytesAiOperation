<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class ServiceOrphanRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'service.orphan';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $services = $this->whmcsBlock($payload, 'services');
        if ($services === null || ! is_array($services['orphan'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($services['orphan'], 'service_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.services.orphan.count' => $aggregate['count'],
                'whmcs.services.orphan.service_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

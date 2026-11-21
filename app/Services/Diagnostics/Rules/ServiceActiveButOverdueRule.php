<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class ServiceActiveButOverdueRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'service.active_but_overdue';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $services = $this->whmcsBlock($payload, 'services');
        if ($services === null || ! is_array($services['active_past_suspend_grace'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($services['active_past_suspend_grace'], 'service_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.services.active_past_suspend_grace.count' => $aggregate['count'],
                'whmcs.services.active_past_suspend_grace.service_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

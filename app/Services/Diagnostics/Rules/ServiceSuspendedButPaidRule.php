<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class ServiceSuspendedButPaidRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'service.suspended_but_paid';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $services = $this->whmcsBlock($payload, 'services');
        if ($services === null || ! is_array($services['suspended_with_paid_invoices'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($services['suspended_with_paid_invoices'], 'service_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.services.suspended_with_paid_invoices.count' => $aggregate['count'],
                'whmcs.services.suspended_with_paid_invoices.service_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

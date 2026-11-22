<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class InvoiceNotGeneratedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'invoice.not_generated';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $billing = $this->whmcsBlock($payload, 'billing');
        if ($billing === null || ! is_array($billing['due_services_without_invoice'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($billing['due_services_without_invoice'], 'service_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.billing.due_services_without_invoice.count' => $aggregate['count'],
                'whmcs.billing.due_services_without_invoice.service_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

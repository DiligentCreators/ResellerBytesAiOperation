<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class InvoiceOverdueNoReminderRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'invoice.overdue_no_reminder';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $billing = $this->whmcsBlock($payload, 'billing');
        if ($billing === null || ! is_array($billing['overdue_without_reminder'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($billing['overdue_without_reminder'], 'invoice_ids');
        if ($aggregate === null) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.billing.overdue_without_reminder.count' => $aggregate['count'],
                'whmcs.billing.overdue_without_reminder.invoice_ids' => $aggregate['ids'],
            ],
            $aggregate['ids'],
        );
    }
}

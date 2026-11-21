<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class OrdersPendingPaidRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'orders.pending_paid';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $services = $this->whmcsBlock($payload, 'services');
        if ($services === null || ! is_array($services['pending_paid_orders'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($services['pending_paid_orders'], 'order_ids');
        if ($aggregate === null) {
            return null;
        }

        $oldest = $services['pending_paid_orders']['oldest_age_minutes'] ?? null;

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.services.pending_paid_orders.count' => $aggregate['count'],
                'whmcs.services.pending_paid_orders.order_ids' => $aggregate['ids'],
                'whmcs.services.pending_paid_orders.oldest_age_minutes' => is_numeric($oldest) ? (int) $oldest : null,
            ],
            $aggregate['ids'],
        );
    }
}

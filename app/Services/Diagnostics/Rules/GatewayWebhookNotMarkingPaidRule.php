<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class GatewayWebhookNotMarkingPaidRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'gateway.webhook_not_marking_paid';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $gateways = $this->whmcsBlock($payload, 'gateways');
        if ($gateways === null) {
            return null;
        }

        $unmatched = $gateways['unmatched_success_7d'] ?? null;
        if (! is_array($unmatched) || $unmatched === []) {
            return null;
        }

        $total = 0;
        $byName = [];
        foreach ($unmatched as $name => $count) {
            if (! is_string($name) || ! is_numeric($count) || (int) $count < 1) {
                continue;
            }
            $byName[$name] = (int) $count;
            $total += (int) $count;
        }

        if ($total < 1) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $total,
            ['whmcs.gateways.unmatched_success_7d' => $byName],
        );
    }
}

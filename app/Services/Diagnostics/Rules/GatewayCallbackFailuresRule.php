<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class GatewayCallbackFailuresRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'gateway.callback_failures';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $gateways = $this->whmcsBlock($payload, 'gateways');
        if ($gateways === null) {
            return null;
        }

        $threshold = $this->intSetting('monitoring.gateway_failure_threshold_7d', 3);
        $total = 0;
        $evidence = [];

        foreach (['failures_7d', 'signature_failures_7d'] as $field) {
            $map = $gateways[$field] ?? null;
            if (! is_array($map)) {
                continue;
            }
            $normalized = [];
            foreach ($map as $name => $count) {
                if (! is_string($name) || ! is_numeric($count) || (int) $count < 1) {
                    continue;
                }
                $normalized[$name] = (int) $count;
                $total += (int) $count;
            }
            if ($normalized !== []) {
                $evidence['whmcs.gateways.'.$field] = $normalized;
            }
        }

        if ($total < $threshold || $evidence === []) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $total,
            $evidence,
        );
    }
}

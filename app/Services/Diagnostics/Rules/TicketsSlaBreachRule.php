<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class TicketsSlaBreachRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'tickets.sla_breach';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $tickets = $this->whmcsBlock($payload, 'tickets');
        if ($tickets === null || ! is_array($tickets['sla_breach'] ?? null)) {
            return null;
        }

        $aggregate = $this->countAggregate($tickets['sla_breach'], 'ticket_ids');
        if ($aggregate === null) {
            return null;
        }

        $thresholdHours = is_numeric($tickets['threshold_hours'] ?? null)
            ? (int) $tickets['threshold_hours']
            : $this->intSetting('monitoring.ticket_sla_hours', 24);

        $oldestHours = is_numeric($tickets['sla_breach']['oldest_hours'] ?? null)
            ? (int) $tickets['sla_breach']['oldest_hours']
            : null;

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $aggregate['count'],
            [
                'whmcs.tickets.threshold_hours' => $thresholdHours,
                'whmcs.tickets.sla_breach.count' => $aggregate['count'],
                'whmcs.tickets.sla_breach.ticket_ids' => $aggregate['ids'],
                'whmcs.tickets.sla_breach.oldest_hours' => $oldestHours,
            ],
            $aggregate['ids'],
        );
    }
}

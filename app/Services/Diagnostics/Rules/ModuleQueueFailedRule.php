<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class ModuleQueueFailedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'module.queue_failed';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $queue = $this->whmcsBlock($payload, 'module_queue');
        if ($queue === null) {
            return null;
        }

        $pending = $queue['pending_count'] ?? null;
        if (! is_numeric($pending)) {
            return null;
        }

        $pending = (int) $pending;
        $oldest = is_numeric($queue['oldest_age_minutes'] ?? null) ? (int) $queue['oldest_age_minutes'] : null;
        $stuckMinutes = $this->intSetting('monitoring.module_queue_stuck_minutes', 60);

        $failedItems = [];
        $items = $queue['items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $retries = is_numeric($item['retries'] ?? null) ? (int) $item['retries'] : 0;
                $error = is_string($item['error'] ?? null) ? trim($item['error']) : '';
                if ($retries > 0 || $error !== '') {
                    if (is_numeric($item['id'] ?? null)) {
                        $failedItems[] = (int) $item['id'];
                    }
                }
            }
        }

        $isStuck = $pending > 0 && $oldest !== null && $oldest >= $stuckMinutes;
        if ($failedItems === [] && ! $isStuck) {
            return null;
        }

        $ids = array_values(array_unique(array_slice($failedItems, 0, 50)));

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            max($pending, count($ids)),
            [
                'whmcs.module_queue.pending_count' => $pending,
                'whmcs.module_queue.oldest_age_minutes' => $oldest,
                'whmcs.module_queue.failed_item_ids' => $ids,
            ],
            $ids,
        );
    }
}

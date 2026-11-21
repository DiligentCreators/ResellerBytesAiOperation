<?php

namespace App\Services\Diagnostics;

/**
 * Prepares a diagnostic snapshot for AI analysis by removing ID lists that
 * inflate token usage and are unused by the model instructions.
 */
class AiSnapshotPayloadTrimmer
{
    private const ID_LIST_KEYS = [
        'invoice_ids',
        'service_ids',
        'order_ids',
        'domain_ids',
        'ticket_ids',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function trim(array $payload): array
    {
        $trimmed = $payload;

        if (isset($trimmed['whmcs']) && is_array($trimmed['whmcs'])) {
            $trimmed['whmcs'] = $this->trimWhmcs($trimmed['whmcs']);
        }

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $whmcs
     * @return array<string, mixed>
     */
    private function trimWhmcs(array $whmcs): array
    {
        foreach ($whmcs as $blockKey => $block) {
            if (! is_array($block)) {
                continue;
            }

            if ($blockKey === 'module_queue' && isset($block['items'])) {
                unset($block['items']);
            }

            $whmcs[$blockKey] = $this->stripIdLists($block);
        }

        return $whmcs;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function stripIdLists(array $node): array
    {
        foreach ($node as $key => $value) {
            if (in_array($key, self::ID_LIST_KEYS, true)) {
                unset($node[$key]);

                continue;
            }

            if (is_array($value)) {
                $node[$key] = $this->stripIdLists($value);
            }
        }

        return $node;
    }
}

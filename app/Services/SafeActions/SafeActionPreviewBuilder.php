<?php

namespace App\Services\SafeActions;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use InvalidArgumentException;

class SafeActionPreviewBuilder
{
    public function __construct(
        private readonly SafeActionRegistry $registry,
    ) {}

    /**
     * @param  list<int>|null  $selectedIds
     * @param  array<string, mixed>  $resolvedParams
     * @return array{
     *     action_key: string,
     *     label: string,
     *     description: string,
     *     mode: string,
     *     items: list<array{id: int|null, command: string, params: array<string, mixed>, note?: string}>,
     *     refused: list<array{id: int|null, reason: string}>,
     *     target_ids: list<int>,
     *     items_total: int
     * }
     */
    public function build(
        string $actionKey,
        WhmcsIntegration $integration,
        ?DiagnosticFinding $finding = null,
        ?array $selectedIds = null,
        string $mode = 'one_click',
        array $resolvedParams = [],
    ): array {
        $definition = $this->registry->get($actionKey);
        $allowedIds = $this->affectedIds($finding, $integration, $actionKey);
        $targetIds = $this->resolveTargetIds($definition, $allowedIds, $selectedIds, $mode, $finding !== null);

        $items = [];
        $refused = [];

        if ($actionKey === 'whmcs_clear_template_cache') {
            $items[] = [
                'id' => null,
                'command' => 'clear_templates_c',
                'params' => ['path' => 'templates_c'],
                'note' => 'Deletes compiled files only inside WHMCS templates_c.',
            ];
        } elseif ($actionKey === 'module_queue_retry') {
            $queueItems = $this->moduleQueueItems($integration, $targetIds);
            foreach ($targetIds as $id) {
                $item = $queueItems[$id] ?? null;
                $action = is_array($item) ? strtolower((string) ($item['action'] ?? '')) : '';
                $serviceId = is_array($item) && is_numeric($item['service_id'] ?? null)
                    ? (int) $item['service_id']
                    : null;

                if (in_array($action, ['terminate', 'terminateaccount', 'moduleterminate'], true)) {
                    $refused[] = ['id' => $id, 'reason' => 'terminate_refused'];

                    continue;
                }

                $command = match ($action) {
                    'create', 'modulecreate' => 'ModuleCreate',
                    'suspend', 'modulesuspend' => 'ModuleSuspend',
                    'unsuspend', 'moduleunsuspend' => 'ModuleUnsuspend',
                    default => null,
                };

                if ($command === null || $serviceId === null || $serviceId < 1) {
                    $refused[] = ['id' => $id, 'reason' => 'unsupported_queue_action'];

                    continue;
                }

                if ($mode === 'auto' && ! in_array($command, $definition['auto_local_api_commands'], true)) {
                    $refused[] = ['id' => $id, 'reason' => 'auto_action_refused'];

                    continue;
                }

                $items[] = [
                    'id' => $id,
                    'command' => $command,
                    'params' => ['serviceid' => $serviceId],
                    'note' => 'Queue item #'.$id.' → '.$command,
                ];
            }
        } else {
            foreach ($targetIds as $id) {
                $mapped = $this->mapItem($actionKey, $id, $resolvedParams);
                if ($mapped === null) {
                    $refused[] = ['id' => $id, 'reason' => 'unsupported_target'];

                    continue;
                }
                $items[] = $mapped;
            }
        }

        $executableIds = array_values(array_filter(
            array_map(static fn (array $item): ?int => is_int($item['id'] ?? null) ? $item['id'] : null, $items),
        ));

        return [
            'action_key' => $actionKey,
            'label' => $definition['label'],
            'description' => $definition['description'],
            'mode' => $mode,
            'items' => $items,
            'refused' => $refused,
            'target_ids' => $actionKey === 'whmcs_clear_template_cache' ? [] : $executableIds,
            'items_total' => count($items),
        ];
    }

    /**
     * @return list<int>
     */
    public function affectedIds(
        ?DiagnosticFinding $finding,
        WhmcsIntegration $integration,
        string $actionKey,
    ): array {
        if ($actionKey === 'whmcs_clear_template_cache') {
            return [];
        }

        // When a finding is bound, only its evidence IDs are authoritative.
        if ($finding !== null) {
            return $this->idsFromEvidence(is_array($finding->evidence) ? $finding->evidence : []);
        }

        return $this->idsFromLatestSnapshot($integration, null, $actionKey);
    }

    /**
     * @param  array{
     *     bulk_allowed: bool,
     *     max_items: int
     * }  $definition
     * @param  list<int>  $allowedIds
     * @param  list<int>|null  $selectedIds
     * @return list<int>
     */
    private function resolveTargetIds(
        array $definition,
        array $allowedIds,
        ?array $selectedIds,
        string $mode,
        bool $findingBound,
    ): array {
        if ($selectedIds !== null && $selectedIds !== []) {
            $selected = $this->normalizeIds($selectedIds);
            if ($findingBound && $allowedIds === []) {
                throw new InvalidArgumentException('This finding has no affected targets; explicit target_ids are not allowed.');
            }

            $allowedLookup = array_fill_keys($allowedIds, true);
            $targets = [];
            foreach ($selected as $id) {
                if ($allowedIds !== [] && ! isset($allowedLookup[$id])) {
                    throw new InvalidArgumentException('Selected target is not in the finding affected set.');
                }
                // Without a finding, empty allowed set must not accept arbitrary IDs.
                if ($allowedIds === [] && ! $findingBound) {
                    throw new InvalidArgumentException('Selected target is not in the finding affected set.');
                }
                $targets[] = $id;
            }
        } elseif ($allowedIds === [] && $selectedIds === null) {
            return [];
        } else {
            $targets = $allowedIds;
        }

        $max = (int) $definition['max_items'];
        if ($mode === 'bulk' || count($targets) > 1) {
            if (! $definition['bulk_allowed'] && count($targets) > 1) {
                throw new InvalidArgumentException('This safe action does not allow bulk targets.');
            }
            if (count($targets) > $max) {
                throw new InvalidArgumentException('Selected targets exceed the bulk max items cap.');
            }
        }

        if (! $definition['bulk_allowed']) {
            $targets = array_slice($targets, 0, 1);
        } else {
            $targets = array_slice($targets, 0, $max);
        }

        return array_values($targets);
    }

    /**
     * @param  array<string, mixed>  $resolvedParams
     * @return array{id: int, command: string, params: array<string, mixed>, note?: string}|null
     */
    private function mapItem(string $actionKey, int $id, array $resolvedParams = []): ?array
    {
        return match ($actionKey) {
            'service_unsuspend_paid' => [
                'id' => $id,
                'command' => 'ModuleUnsuspend',
                'params' => ['serviceid' => $id],
            ],
            'invoice_send_overdue_reminder' => [
                'id' => $id,
                'command' => 'SendEmail',
                'params' => [
                    'messagename' => $this->overdueMessageName($resolvedParams),
                    'id' => $id,
                ],
            ],
            'invoice_generate_due' => [
                'id' => $id,
                'command' => 'GenInvoices',
                'params' => [
                    'serviceids' => [$id],
                    'noemails' => false,
                ],
            ],
            'order_accept_paid' => [
                'id' => $id,
                'command' => 'AcceptOrder',
                'params' => [
                    'orderid' => $id,
                    'autosetup' => true,
                    'sendemail' => true,
                ],
            ],
            'ticket_escalate_sla' => [
                'id' => $id,
                'command' => 'UpdateTicket',
                'params' => [
                    'ticketid' => $id,
                    'priority' => 'High',
                ],
            ],
            'domain_renew_retry' => [
                'id' => $id,
                'command' => 'DomainRenew',
                'params' => ['domainid' => $id],
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<int>
     */
    private function idsFromEvidence(array $evidence): array
    {
        foreach ($evidence as $value) {
            if (! is_array($value)) {
                continue;
            }
            $ids = $this->normalizeIds($value);
            if ($ids !== []) {
                return $ids;
            }
        }

        return [];
    }

    /**
     * @return list<int>
     */
    private function idsFromLatestSnapshot(
        WhmcsIntegration $integration,
        ?string $issueKey,
        string $actionKey,
    ): array {
        $latest = DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->latest('received_at')
            ->first();

        if ($latest === null || ! is_array($latest->payload)) {
            return [];
        }

        $payload = $latest->payload;
        $path = match ($issueKey ?? $actionKey) {
            'module.queue_failed', 'module_queue_retry' => 'whmcs.module_queue.items',
            'service.suspended_but_paid', 'service_unsuspend_paid' => 'whmcs.services.suspended_with_paid_invoices.service_ids',
            'invoice.overdue_no_reminder', 'invoice_send_overdue_reminder' => 'whmcs.billing.overdue_without_reminder.invoice_ids',
            'invoice.not_generated', 'invoice_generate_due' => 'whmcs.billing.due_services_without_invoice.service_ids',
            'orders.pending_paid', 'order_accept_paid' => 'whmcs.services.pending_paid_orders.order_ids',
            'tickets.sla_breach', 'ticket_escalate_sla' => 'whmcs.tickets.sla_breach.ticket_ids',
            'domain.renewal_failed', 'domain_renew_retry' => 'whmcs.domains.renewal_failed_7d.domain_ids',
            default => null,
        };

        if ($path === null) {
            return [];
        }

        $value = data_get($payload, $path);
        if ($path === 'whmcs.module_queue.items' && is_array($value)) {
            $ids = [];
            foreach ($value as $item) {
                if (is_array($item) && is_numeric($item['id'] ?? null)) {
                    $ids[] = (int) $item['id'];
                }
            }

            return array_values(array_slice(array_unique($ids), 0, 50));
        }

        return is_array($value) ? $this->normalizeIds($value) : [];
    }

    /**
     * @param  list<int>  $targetIds
     * @return array<int, array<string, mixed>>
     */
    private function moduleQueueItems(WhmcsIntegration $integration, array $targetIds): array
    {
        $latest = DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->latest('received_at')
            ->first();

        $items = data_get($latest?->payload, 'whmcs.module_queue.items', []);
        if (! is_array($items)) {
            return [];
        }

        $lookup = [];
        $wanted = array_fill_keys($targetIds, true);
        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['id'] ?? null)) {
                continue;
            }
            $id = (int) $item['id'];
            if (isset($wanted[$id])) {
                $lookup[$id] = $item;
            }
        }

        return $lookup;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private function normalizeIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_slice(array_unique($ids), 0, 50));
    }

    /**
     * @param  array<string, mixed>  $resolvedParams
     */
    private function overdueMessageName(array $resolvedParams = []): string
    {
        $fromResolved = $resolvedParams['messagename'] ?? null;
        if (is_string($fromResolved) && $fromResolved !== '') {
            return $fromResolved;
        }

        return $this->registry->overdueReminderMessageName();
    }
}

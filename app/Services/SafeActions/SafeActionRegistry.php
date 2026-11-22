<?php

namespace App\Services\SafeActions;

use App\Services\PlatformSettings;
use InvalidArgumentException;

class SafeActionRegistry
{
    public const FIXES_DISABLED_MESSAGE = 'One-click and bulk fixes are currently disabled by the platform.';

    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     risk_level: string,
     *     requires_password_confirm: bool,
     *     requires_finding: bool,
     *     auto_verify: bool,
     *     execution_target: string,
     *     entitlement: string,
     *     bulk_allowed: bool,
     *     max_items: int,
     *     auto_allowed: bool,
     *     auto_local_api_commands: list<string>,
     *     local_api_commands: list<string>
     * }>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> $actions */
        $actions = config('safe_actions.actions', []);

        $normalized = [];
        foreach ($actions as $key => $definition) {
            $normalized[$key] = $this->normalize($key, $definition);
        }

        return $normalized;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     risk_level: string,
     *     requires_password_confirm: bool,
     *     requires_finding: bool,
     *     auto_verify: bool,
     *     execution_target: string,
     *     entitlement: string,
     *     bulk_allowed: bool,
     *     max_items: int,
     *     auto_allowed: bool,
     *     auto_local_api_commands: list<string>,
     *     local_api_commands: list<string>
     * }
     */
    public function get(string $actionKey): array
    {
        $actions = config('safe_actions.actions', []);
        if (! is_array($actions) || ! array_key_exists($actionKey, $actions) || ! is_array($actions[$actionKey])) {
            throw new InvalidArgumentException('Action is not on the Safe Action allowlist.');
        }

        return $this->normalize($actionKey, $actions[$actionKey]);
    }

    public function isAllowlisted(string $actionKey): bool
    {
        $actions = config('safe_actions.actions', []);

        return is_array($actions) && array_key_exists($actionKey, $actions);
    }

    public function isAddonExecuted(string $actionKey): bool
    {
        return $this->get($actionKey)['execution_target'] === 'addon';
    }

    /**
     * @return list<string>
     */
    public function forbiddenLocalApiCommands(): array
    {
        return [
            'RefundInvoice',
            'ModuleTerminate',
            'UpdateInvoice',
            'AddInvoicePayment',
            'AddCredit',
            'ApplyCredit',
            'UpdatePaymentGateway',
            'ActivatePaymentGateway',
            'DeactivatePaymentGateway',
            'UpgradeProduct',
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     risk_level: string,
     *     requires_password_confirm: bool,
     *     requires_finding: bool,
     *     auto_verify: bool,
     *     execution_target: string,
     *     entitlement: string,
     *     bulk_allowed: bool,
     *     max_items: int,
     *     auto_allowed: bool,
     *     auto_local_api_commands: list<string>,
     *     local_api_commands: list<string>
     * }
     */
    private function normalize(string $key, array $definition): array
    {
        $target = (string) ($definition['execution_target'] ?? 'platform');
        if (! in_array($target, ['platform', 'addon'], true)) {
            $target = 'platform';
        }

        $bulkMax = $this->bulkMaxItems();
        $configuredMax = $definition['max_items'] ?? null;
        $maxItems = is_numeric($configuredMax) ? min((int) $configuredMax, $bulkMax) : $bulkMax;
        if ($maxItems < 1) {
            $maxItems = 1;
        }

        $commands = [];
        foreach ((array) ($definition['local_api_commands'] ?? []) as $command) {
            if (is_string($command) && $command !== '') {
                $commands[] = $command;
            }
        }
        $commands = array_values(array_unique($commands));

        $autoCommands = [];
        foreach ((array) ($definition['auto_local_api_commands'] ?? []) as $command) {
            if (is_string($command) && $command !== '') {
                $autoCommands[] = $command;
            }
        }

        $requiresPasswordConfirm = (bool) ($definition['requires_password_confirm'] ?? false);
        // Email-sending LocalAPI actions always require password confirmation.
        if (in_array('SendEmail', $commands, true)) {
            $requiresPasswordConfirm = true;
        }

        return [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'description' => (string) ($definition['description'] ?? ''),
            'risk_level' => (string) ($definition['risk_level'] ?? 'low'),
            'requires_password_confirm' => $requiresPasswordConfirm,
            'requires_finding' => (bool) ($definition['requires_finding'] ?? false),
            'auto_verify' => (bool) ($definition['auto_verify'] ?? true),
            'execution_target' => $target,
            'entitlement' => (string) ($definition['entitlement'] ?? 'safe_actions.enabled'),
            'bulk_allowed' => (bool) ($definition['bulk_allowed'] ?? false),
            'max_items' => $maxItems,
            'auto_allowed' => (bool) ($definition['auto_allowed'] ?? false),
            'auto_local_api_commands' => array_values(array_unique($autoCommands)),
            'local_api_commands' => $commands,
        ];
    }

    public function bulkMaxItems(): int
    {
        try {
            $value = $this->settings->get('fixes.bulk_max_items');
        } catch (\Throwable) {
            $value = config('fixes.bulk_max_items', 50);
        }

        $max = is_numeric($value) ? (int) $value : 50;

        return max(1, min(500, $max));
    }

    public function fixesEnabled(): bool
    {
        try {
            return (bool) $this->settings->get('fixes.enabled');
        } catch (\Throwable) {
            return (bool) config('fixes.enabled', true);
        }
    }

    public function isOneClickOrBulkAction(string $actionKey): bool
    {
        if (! $this->isAllowlisted($actionKey)) {
            return false;
        }

        return $this->get($actionKey)['entitlement'] === 'fixes.one_click';
    }

    /**
     * Server-resolved free-form command params (never taken from the client).
     *
     * @return array<string, mixed>
     */
    public function resolveParams(string $actionKey): array
    {
        if ($actionKey !== 'invoice_send_overdue_reminder') {
            return [];
        }

        return [
            'messagename' => $this->overdueReminderMessageName(),
        ];
    }

    public function overdueReminderMessageName(): string
    {
        try {
            $value = $this->settings->get('fixes.overdue_reminder_messagename');
        } catch (\Throwable) {
            $value = config('fixes.overdue_reminder_messagename', 'First Invoice Overdue Notice');
        }

        return is_string($value) && $value !== ''
            ? $value
            : 'First Invoice Overdue Notice';
    }
}

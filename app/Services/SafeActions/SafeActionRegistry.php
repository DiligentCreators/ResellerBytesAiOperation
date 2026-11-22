<?php

namespace App\Services\SafeActions;

use App\Services\PlatformSettings;
use InvalidArgumentException;

class SafeActionRegistry
{
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

        return [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'description' => (string) ($definition['description'] ?? ''),
            'risk_level' => (string) ($definition['risk_level'] ?? 'low'),
            'requires_password_confirm' => (bool) ($definition['requires_password_confirm'] ?? false),
            'requires_finding' => (bool) ($definition['requires_finding'] ?? false),
            'auto_verify' => (bool) ($definition['auto_verify'] ?? true),
            'execution_target' => $target,
            'entitlement' => (string) ($definition['entitlement'] ?? 'safe_actions.enabled'),
            'bulk_allowed' => (bool) ($definition['bulk_allowed'] ?? false),
            'max_items' => $maxItems,
            'auto_allowed' => (bool) ($definition['auto_allowed'] ?? false),
            'local_api_commands' => array_values(array_unique($commands)),
        ];
    }

    private function bulkMaxItems(): int
    {
        try {
            $value = $this->settings->get('fixes.bulk_max_items');
        } catch (\Throwable) {
            $value = config('fixes.bulk_max_items', 50);
        }

        $max = is_numeric($value) ? (int) $value : 50;

        return max(1, min(500, $max));
    }
}

<?php

namespace App\Services\SafeActions;

use InvalidArgumentException;

class SafeActionRegistry
{
    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     risk_level: string,
     *     requires_password_confirm: bool,
     *     requires_finding: bool,
     *     auto_verify: bool,
     *     entitlement: string
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
     *     entitlement: string
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
     *     entitlement: string
     * }
     */
    private function normalize(string $key, array $definition): array
    {
        return [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'description' => (string) ($definition['description'] ?? ''),
            'risk_level' => (string) ($definition['risk_level'] ?? 'low'),
            'requires_password_confirm' => (bool) ($definition['requires_password_confirm'] ?? false),
            'requires_finding' => (bool) ($definition['requires_finding'] ?? false),
            'auto_verify' => (bool) ($definition['auto_verify'] ?? true),
            'entitlement' => (string) ($definition['entitlement'] ?? 'safe_actions.enabled'),
        ];
    }
}

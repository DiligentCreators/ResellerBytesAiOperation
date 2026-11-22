<?php

namespace App\Support\Operations;

use App\Services\PlatformSettings;
use RuntimeException;

/**
 * Resolves the local DemoAccountsSeeder password.
 *
 * Production fails closed when unset. Outside production, a random password is
 * generated once and stored encrypted in platform_settings.
 */
class DemoAccountsPassword
{
    public const SETTING_KEY = 'operations.demo_accounts.password';

    public function __construct(private readonly PlatformSettings $settings) {}

    public function resolve(): string
    {
        $existing = $this->readExisting();
        if ($existing !== null) {
            return $existing;
        }

        if (app()->isProduction()) {
            throw new RuntimeException('operations_demo_accounts_password_missing');
        }

        $generated = bin2hex(random_bytes(32));
        $this->settings->set(self::SETTING_KEY, $generated);

        return $generated;
    }

    public function isConfigured(): bool
    {
        return $this->readExisting() !== null;
    }

    private function readExisting(): ?string
    {
        $fromSettings = $this->settings->get(self::SETTING_KEY);
        if (is_string($fromSettings) && trim($fromSettings) !== '') {
            return $fromSettings;
        }

        $fromConfig = config('operations.demo_accounts.password');
        if (is_string($fromConfig) && trim($fromConfig) !== '') {
            return $fromConfig;
        }

        return null;
    }
}

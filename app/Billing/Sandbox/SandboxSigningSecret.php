<?php

namespace App\Billing\Sandbox;

use App\Services\PlatformSettings;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolves the HMAC secret used to sign and verify sandbox billing webhooks.
 *
 * Production fails closed when unset. Outside production, a random secret is
 * generated once and stored encrypted in platform_settings.
 */
class SandboxSigningSecret
{
    public const SETTING_KEY = 'billing.sandbox.signing_secret';

    public function __construct(private readonly PlatformSettings $settings) {}

    public function resolve(): string
    {
        $existing = $this->readExisting();
        if ($existing !== null) {
            return $existing;
        }

        if (app()->isProduction()) {
            throw new RuntimeException('billing_sandbox_signing_secret_missing');
        }

        $generated = Str::random(64);
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

        $fromConfig = config('billing.sandbox.signing_secret');
        if (is_string($fromConfig) && trim($fromConfig) !== '') {
            return $fromConfig;
        }

        return null;
    }
}

<?php

namespace App\Services\Billing;

use App\Models\BillingGatewaySetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Resolves billing gateway settings with DB-over-env fallback.
 * Reads fresh from the database on every call (safe for long-running queue workers).
 */
class BillingSettings
{
    public function gateway(): string
    {
        $fromDb = $this->nonEmptyString($this->record()?->gateway);
        if ($fromDb !== null) {
            return $fromDb;
        }

        $fromConfig = $this->nonEmptyString(config('billing.default_gateway'));

        return $fromConfig ?? 'sandbox';
    }

    public function kelviqMode(): string
    {
        $fromDb = $this->nonEmptyString($this->record()?->kelviq_mode);
        if ($fromDb !== null) {
            return $fromDb;
        }

        $fromConfig = $this->nonEmptyString(config('billing.kelviq.mode'));

        return $fromConfig ?? 'sandbox';
    }

    public function kelviqApiKey(): string
    {
        return $this->kelviqApiKeyForMode($this->kelviqMode());
    }

    public function kelviqApiKeyForMode(string $mode): string
    {
        $column = $mode === 'live' ? 'kelviq_live_api_key' : 'kelviq_sandbox_api_key';
        $fromDb = $this->nonEmptyString($this->record()?->{$column});
        if ($fromDb !== null) {
            return $fromDb;
        }

        return (string) (config('billing.kelviq.api_key') ?? '');
    }

    public function kelviqWebhookSecret(): string
    {
        return $this->kelviqWebhookSecretForMode($this->kelviqMode());
    }

    public function kelviqWebhookSecretForMode(string $mode): string
    {
        $column = $mode === 'live' ? 'kelviq_live_webhook_secret' : 'kelviq_sandbox_webhook_secret';
        $fromDb = $this->nonEmptyString($this->record()?->{$column});
        if ($fromDb !== null) {
            return $fromDb;
        }

        return (string) (config('billing.kelviq.webhook_secret') ?? '');
    }

    public function kelviqBaseUrl(): string
    {
        return $this->kelviqMode() === 'live'
            ? (string) config('billing.kelviq.live_base_url')
            : (string) config('billing.kelviq.sandbox_base_url');
    }

    public function kelviqSandboxBaseUrl(): string
    {
        return (string) config('billing.kelviq.sandbox_base_url');
    }

    public function kelviqLiveBaseUrl(): string
    {
        return (string) config('billing.kelviq.live_base_url');
    }

    public function trialsEnabled(): bool
    {
        $record = $this->record();
        if ($record !== null) {
            return (bool) $record->trials_enabled;
        }

        return false;
    }

    public function trialCardRequired(): bool
    {
        $record = $this->record();
        if ($record !== null) {
            return (bool) $record->trial_card_required;
        }

        return true;
    }

    public function trialFirstTimeOnly(): bool
    {
        $record = $this->record();
        if ($record !== null) {
            return (bool) $record->trial_first_time_only;
        }

        return true;
    }

    /**
     * Whether the effective value for a field comes from the database row or env/config.
     *
     * @return 'database'|'env'
     */
    public function source(string $field): string
    {
        $record = $this->record();

        return match ($field) {
            'gateway' => $this->nonEmptyString($record?->gateway) !== null ? 'database' : 'env',
            'kelviq_mode' => $this->nonEmptyString($record?->kelviq_mode) !== null ? 'database' : 'env',
            'kelviq_sandbox_api_key' => $this->nonEmptyString($record?->kelviq_sandbox_api_key) !== null ? 'database' : 'env',
            'kelviq_live_api_key' => $this->nonEmptyString($record?->kelviq_live_api_key) !== null ? 'database' : 'env',
            'kelviq_sandbox_webhook_secret' => $this->nonEmptyString($record?->kelviq_sandbox_webhook_secret) !== null ? 'database' : 'env',
            'kelviq_live_webhook_secret' => $this->nonEmptyString($record?->kelviq_live_webhook_secret) !== null ? 'database' : 'env',
            'trials_enabled', 'trial_card_required', 'trial_first_time_only' => $record !== null ? 'database' : 'env',
            default => 'env',
        };
    }

    /**
     * @return array{configured: bool, source: 'database'|'env', mask: string|null}
     */
    public function secretPresentation(string $field): array
    {
        $value = match ($field) {
            'kelviq_sandbox_api_key' => $this->kelviqApiKeyForMode('sandbox'),
            'kelviq_live_api_key' => $this->kelviqApiKeyForMode('live'),
            'kelviq_sandbox_webhook_secret' => $this->kelviqWebhookSecretForMode('sandbox'),
            'kelviq_live_webhook_secret' => $this->kelviqWebhookSecretForMode('live'),
            default => '',
        };

        $configured = $value !== '';

        return [
            'configured' => $configured,
            'source' => $this->source($field),
            'mask' => $configured ? $this->maskSecret($value) : null,
        ];
    }

    public function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $suffix = mb_substr($value, -4);

        return '••••'.$suffix;
    }

    private function record(): ?BillingGatewaySetting
    {
        if (! $this->tableReady()) {
            return null;
        }

        try {
            return BillingGatewaySetting::query()->find(1);
        } catch (Throwable) {
            return null;
        }
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('billing_gateway_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}

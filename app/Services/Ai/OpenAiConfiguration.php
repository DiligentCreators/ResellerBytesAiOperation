<?php

namespace App\Services\Ai;

use App\Models\PlatformAiSettings;

class OpenAiConfiguration
{
    /** @var list<string> */
    public const ALLOWED_MODELS = [
        'gpt-5.6-sol',
        'gpt-5.2',
        'gpt-5.1',
        'gpt-4.1',
        'gpt-4o',
        'o4-mini',
        'o3',
        'o3-mini',
    ];

    /** @var list<string> */
    public const REASONING_EFFORTS = [
        'low',
        'medium',
        'high',
    ];

    /** @return array<string, mixed> */
    public function effective(): array
    {
        $config = [
            'api_key' => (string) config('services.openai.key', ''),
            'model' => (string) config('services.openai.model', 'gpt-5.6-sol'),
            'reasoning_effort' => (string) config('services.openai.reasoning_effort', 'medium'),
            'timeout' => (int) config('services.openai.timeout', 45),
            'signing_secret_key' => (string) config('services.whmcs_addon.signing_secret_key', ''),
            'signing_public_key' => (string) config('services.whmcs_addon.signing_public_key', ''),
        ];

        $settings = PlatformAiSettings::query()->find(1);
        $saved = $settings instanceof PlatformAiSettings ? (array) $settings->configuration : [];

        foreach ($saved as $key => $value) {
            if ($value !== null && $value !== '') {
                $config[$key] = $value;
            }
        }

        foreach ((array) ($saved['_cleared'] ?? []) as $key => $cleared) {
            if ($cleared === true) {
                $config[$key] = '';
            }
        }

        return $config;
    }

    public function apiKey(): string
    {
        return (string) ($this->effective()['api_key'] ?? '');
    }

    public function model(): string
    {
        $model = (string) ($this->effective()['model'] ?? 'gpt-5.6-sol');

        return in_array($model, self::ALLOWED_MODELS, true) ? $model : 'gpt-5.6-sol';
    }

    public function reasoningEffort(): string
    {
        $effort = (string) ($this->effective()['reasoning_effort'] ?? 'medium');

        return in_array($effort, self::REASONING_EFFORTS, true) ? $effort : 'medium';
    }

    public function timeout(): int
    {
        return max(5, (int) ($this->effective()['timeout'] ?? 45));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function signingSecretKey(): string
    {
        return (string) ($this->effective()['signing_secret_key'] ?? '');
    }

    public function signingPublicKey(): string
    {
        return (string) ($this->effective()['signing_public_key'] ?? '');
    }
}

<?php

namespace App\Console\Commands;

use App\Billing\Kelviq\KelviqClient;
use App\Services\Billing\BillingSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('billing:kelviq:check')]
#[Description('Validate Kelviq billing config and optionally perform one harmless API read')]
class CheckKelviqBilling extends Command
{
    public function handle(KelviqClient $client, BillingSettings $settings): int
    {
        $mode = $settings->kelviqMode();
        $apiKeyConfigured = $settings->kelviqApiKey() !== '';
        $webhookSecretConfigured = $settings->kelviqWebhookSecret() !== '';
        $gateway = $settings->gateway();

        $this->info('Kelviq billing configuration check');
        $this->line('gateway='.$gateway);
        $this->line('mode='.$mode);
        $this->line('api_key_configured='.($apiKeyConfigured ? 'yes' : 'no'));
        $this->line('webhook_secret_configured='.($webhookSecretConfigured ? 'yes' : 'no'));
        $this->line('sandbox_base_url='.$settings->kelviqSandboxBaseUrl());
        $this->line('live_base_url='.$settings->kelviqLiveBaseUrl());
        $this->line('webhook_url='.url('/webhooks/kelviq'));

        if ($mode === 'live' && (! $apiKeyConfigured || ! $webhookSecretConfigured)) {
            $this->error('Live mode requires a resolvable API key and webhook secret (database or env).');

            return self::FAILURE;
        }

        if (! $apiKeyConfigured) {
            $this->warn('No API key configured; skipping remote read.');

            return self::SUCCESS;
        }

        try {
            $client->listWebhookLogs(pageSize: 1);
            $this->info('Remote read OK: GET /webhook/logs/');
        } catch (Throwable $exception) {
            $this->error('Remote read failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

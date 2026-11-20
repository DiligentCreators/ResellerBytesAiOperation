<?php

namespace App\Console\Commands;

use App\Billing\Kelviq\KelviqClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('billing:kelviq:check')]
#[Description('Validate Kelviq billing config and optionally perform one harmless API read')]
class CheckKelviqBilling extends Command
{
    public function handle(KelviqClient $client): int
    {
        $mode = (string) config('billing.kelviq.mode', 'sandbox');
        $apiKeyConfigured = (string) config('billing.kelviq.api_key') !== '';
        $webhookSecretConfigured = (string) config('billing.kelviq.webhook_secret') !== '';
        $gateway = (string) config('billing.default_gateway');

        $this->info('Kelviq billing configuration check');
        $this->line('gateway='.$gateway);
        $this->line('mode='.$mode);
        $this->line('api_key_configured='.($apiKeyConfigured ? 'yes' : 'no'));
        $this->line('webhook_secret_configured='.($webhookSecretConfigured ? 'yes' : 'no'));
        $this->line('sandbox_base_url='.(string) config('billing.kelviq.sandbox_base_url'));
        $this->line('live_base_url='.(string) config('billing.kelviq.live_base_url'));
        $this->line('webhook_url='.url('/webhooks/kelviq'));

        if ($mode === 'live' && (! $apiKeyConfigured || ! $webhookSecretConfigured)) {
            $this->error('Live mode requires KELVIQ_API_KEY and KELVIQ_WEBHOOK_SECRET.');

            return self::FAILURE;
        }

        if (! $apiKeyConfigured) {
            $this->warn('No API key configured; skipping remote read.');

            return self::SUCCESS;
        }

        try {
            $client->listWebhookLogs();
            $this->info('Remote read OK: GET /webhook/logs/');
        } catch (Throwable $exception) {
            $this->error('Remote read failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

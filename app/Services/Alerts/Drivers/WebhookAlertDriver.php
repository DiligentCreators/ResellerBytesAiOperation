<?php

namespace App\Services\Alerts\Drivers;

use App\Models\TenantAlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Support\Alerts\WebhookUrlValidator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class WebhookAlertDriver implements AlertChannelDriver
{
    public function __construct(private readonly WebhookUrlValidator $urls) {}

    public function send(TenantAlertChannel $channel, string $alertKey, AlertMessage $message): void
    {
        $url = trim((string) $channel->target);
        $prepared = $this->urls->prepareOutbound($url, allowPrivate: false);
        if (! $prepared['valid']) {
            throw new RuntimeException('alert_webhook_url_invalid');
        }

        $secret = (string) ($channel->signing_secret ?? '');
        if ($secret === '') {
            throw new RuntimeException('alert_webhook_secret_missing');
        }

        $payload = [
            'schema_version' => 1,
            'alert_key' => $alertKey,
            'title' => $message->title,
            'body' => $message->body,
            'installation' => $message->installationName,
            'fix_center_url' => $message->fixCenterUrl,
            'sent_at' => now('UTC')->toIso8601String(),
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, $secret);
        $header = (string) config('alerts.hmac_header', 'X-ResellerBytes-Signature');

        try {
            $response = Http::acceptJson()
                ->withBody($body, 'application/json')
                ->withHeaders([
                    $header => 'sha256='.$signature,
                ])
                ->withoutRedirecting()
                ->withOptions($prepared['request_options'])
                ->connectTimeout(3)
                ->timeout(5)
                ->post($url);
        } catch (Throwable $exception) {
            throw new RuntimeException('alert_webhook_request_failed', 0, $exception);
        }

        if ($response->redirect()) {
            throw new RuntimeException('alert_webhook_redirect_refused');
        }

        if (! $response->successful()) {
            throw new RuntimeException('alert_webhook_rejected');
        }
    }
}

<?php

namespace App\Services\Alerts\Drivers;

use App\Models\TenantAlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Support\Alerts\WebhookUrlValidator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class DiscordAlertDriver implements AlertChannelDriver
{
    public function __construct(private readonly WebhookUrlValidator $urls) {}

    public function send(TenantAlertChannel $channel, string $alertKey, AlertMessage $message): void
    {
        $url = trim((string) $channel->target);
        $prepared = $this->urls->prepareOutbound($url, allowPrivate: false);
        if (! $prepared['valid']) {
            throw new RuntimeException('alert_webhook_url_invalid');
        }

        $content = $message->plainText();
        // Discord content max is 2000 characters.
        if (mb_strlen($content) > 1900) {
            $content = mb_substr($content, 0, 1897).'...';
        }

        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->withOptions($prepared['request_options'])
                ->connectTimeout(3)
                ->timeout(5)
                ->post($url, ['content' => $content]);
        } catch (Throwable $exception) {
            throw new RuntimeException('alert_discord_request_failed', 0, $exception);
        }

        if ($response->redirect()) {
            throw new RuntimeException('alert_discord_redirect_refused');
        }

        if (! $response->successful()) {
            throw new RuntimeException('alert_discord_rejected');
        }
    }
}

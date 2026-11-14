<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Mail\OperationalAlertMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class AlertNotificationDispatcher
{
    /**
     * @param  array{code: string, severity: string, observed: int|float|null, threshold: int|float, window_minutes?: int}  $alert
     * @return array{success: bool, deliveries: array<string, string>}
     */
    public function dispatch(array $alert, ?CarbonImmutable $observedAt = null): array
    {
        $observedAt = ($observedAt ?? CarbonImmutable::now())->utc();
        $message = $this->message($alert, $observedAt);
        $deliveries = [];

        $email = config('operations.alerts.email');
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($email)->send(new OperationalAlertMail(
                    $alert['severity'],
                    $alert['code'],
                    $message,
                ));
                $deliveries['email'] = 'delivered';
            } catch (Throwable) {
                $deliveries['email'] = 'failed';
            }
        } else {
            $deliveries['email'] = 'missing';
        }

        $slackUrl = config('operations.alerts.slack_webhook_url');
        $discordUrl = config('operations.alerts.discord_webhook_url');
        $hasSlack = is_string($slackUrl) && trim($slackUrl) !== '';
        $hasDiscord = is_string($discordUrl) && trim($discordUrl) !== '';

        if ($hasSlack === $hasDiscord) {
            $deliveries['team_channel'] = $hasSlack ? 'failed' : 'missing';
        } else {
            $channel = $hasSlack ? 'slack' : 'discord';
            $url = $hasSlack ? trim((string) $slackUrl) : trim((string) $discordUrl);
            $payload = $channel === 'slack'
                ? ['text' => $message]
                : ['content' => $message];

            $deliveries['team_channel'] = $this->postWebhook($url, $payload)
                ? 'delivered'
                : 'failed';
        }

        if ($alert['severity'] === 'critical') {
            $criticalRoutes = array_filter([
                'whatsapp' => config('operations.alerts.critical_whatsapp_webhook_url'),
                'sms' => config('operations.alerts.critical_sms_webhook_url'),
            ], static fn (mixed $url): bool => is_string($url) && trim($url) !== '');

            if ($criticalRoutes === []) {
                $deliveries['critical_channel'] = 'missing';
            } else {
                foreach ($criticalRoutes as $channel => $url) {
                    $deliveries[$channel] = $this->postWebhook((string) $url, [
                        'schema_version' => 1,
                        'channel' => $channel,
                        'severity' => $alert['severity'],
                        'code' => $alert['code'],
                        'message' => $message,
                        'observed_at' => $observedAt->toIso8601String(),
                    ]) ? 'delivered' : 'failed';
                }
            }
        }

        return [
            'success' => ! in_array('missing', $deliveries, true)
                && ! in_array('failed', $deliveries, true),
            'deliveries' => $deliveries,
        ];
    }

    /**
     * @param  array{code: string, severity: string, observed: int|float|null, threshold: int|float, window_minutes?: int}  $alert
     */
    private function message(array $alert, CarbonImmutable $observedAt): string
    {
        $observed = $alert['observed'] === null ? 'unavailable' : (string) $alert['observed'];
        $window = isset($alert['window_minutes']) ? '; window='.$alert['window_minutes'].'m' : '';

        return sprintf(
            '%s alert %s: observed=%s; threshold=%s%s; observed_at=%s',
            strtoupper($alert['severity']),
            $alert['code'],
            $observed,
            (string) $alert['threshold'],
            $window,
            $observedAt->toIso8601String(),
        );
    }

    /**
     * A successful delivery requires an HTTPS endpoint and a 2xx provider acknowledgement.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(string $url, array $payload): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        try {
            return Http::acceptJson()
                ->timeout(5)
                ->post($url, $payload)
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}

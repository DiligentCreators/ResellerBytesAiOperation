<?php

namespace App\Services\Mail;

use App\Models\PlatformMailSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Throwable;

class CentralOutboundMailer
{
    private function effectiveConfiguration(?PlatformMailSettings $settings): array
    {
        $config = [
            'from_address' => config('platform_mail.from_address'),
            'from_name' => config('platform_mail.from_name'),
            'host' => config('platform_mail.smtp.host'),
            'port' => config('platform_mail.smtp.port'),
            'encryption' => config('platform_mail.smtp.encryption'),
            'username' => config('platform_mail.smtp.username'),
            'password' => config('platform_mail.smtp.password'),
            'server_token' => config('platform_mail.postmark.server_token'),
            'message_stream' => config('platform_mail.postmark.message_stream'),
        ];

        foreach (($settings?->configuration ?? []) as $key => $value) {
            if ($value !== null && $value !== '') {
                $config[$key] = $value;
            }
        }

        foreach (($settings?->configuration['_cleared'] ?? []) as $key => $cleared) {
            if ($cleared === true) {
                $config[$key] = '';
            }
        }

        return $config;
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        $settings = PlatformMailSettings::query()->find(1);
        $driver = $settings?->driver ?? config('platform_mail.driver', 'log');

        if ($driver === 'log') {
            Log::info('outbound_mail.preview', ['driver' => 'log']);
            return;
        }

        $config = $this->effectiveConfiguration($settings);
        $from = (string) ($config['from_address'] ?? '');
        $name = str_replace(["\r", "\n", '"', '<', '>'], '', (string) ($config['from_name'] ?? ''));

        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || $name === '') {
            throw new RuntimeException('platform_mail_sender_configuration_invalid');
        }

        if ($driver === 'smtp') {
            if (empty($config['host']) || (int) ($config['port'] ?? 0) < 1) {
                throw new RuntimeException('platform_mail_smtp_configuration_invalid');
            }

            $encryption = $config['encryption'] ?? 'tls';
            $username = (string) ($config['username'] ?? '');
            $password = (string) ($config['password'] ?? '');

            if (! in_array($encryption, ['tls', 'ssl'], true)) {
                throw new RuntimeException('platform_mail_smtp_configuration_invalid');
            }
            if (($username === '') !== ($password === '')) {
                throw new RuntimeException('platform_mail_smtp_auth_configuration_invalid');
            }

            try {
                $mailer = Mail::build([
                    'transport' => 'smtp',
                    'scheme' => $encryption === 'ssl' ? 'smtps' : null,
                    'host' => $config['host'],
                    'port' => (int) $config['port'],
                    'username' => $username !== '' ? $username : null,
                    'password' => $password !== '' ? $password : null,
                    'timeout' => 10,
                    'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
                ]);

                $mailer->html($html, function ($message) use ($to, $subject, $from, $name, $text): void {
                    $message->to($to)->from($from, $name)->subject($subject)->text($text);
                });
            } catch (Throwable) {
                throw new RuntimeException('platform_mail_smtp_delivery_failed');
            }

            return;
        }

        if ($driver === 'postmark') {
            $token = (string) ($config['server_token'] ?? '');
            $stream = (string) ($config['message_stream'] ?? '');

            if ($token === '' || $stream === '') {
                throw new RuntimeException('platform_mail_postmark_configuration_invalid');
            }

            try {
                Http::acceptJson()
                    ->withHeaders(['X-Postmark-Server-Token' => $token])
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->withOptions(['allow_redirects' => false])
                    ->post('https://api.postmarkapp.com/email', [
                        'From' => (new Address($from, $name))->toString(),
                        'To' => $to,
                        'Subject' => $subject,
                        'HtmlBody' => $html,
                        'TextBody' => $text,
                        'MessageStream' => $stream,
                    ])->throw();
            } catch (Throwable) {
                throw new RuntimeException('platform_mail_postmark_delivery_failed');
            }

            return;
        }

        throw new RuntimeException('platform_mail_driver_invalid');
    }
}

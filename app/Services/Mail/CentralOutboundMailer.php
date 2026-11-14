<?php

namespace App\Services\Mail;

use App\Models\PlatformMailSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class CentralOutboundMailer
{
    public function send(string $to, string $subject, string $html, string $text): void
    {
        $settings = PlatformMailSettings::query()->find(1);
        $driver = $settings?->driver ?? config('platform_mail.driver', 'log');
        $stored = $settings?->configuration ?? [];
        $config = $stored ?: [
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

        if ($driver === 'log') {
            Log::info('outbound_mail.preview', ['driver' => 'log', 'subject' => $subject]);
            return;
        }

        $from = (string) ($config['from_address'] ?? '');
        $name = str_replace(["\\r", "\\n", '"', '<', '>'], '', (string) ($config['from_name'] ?? ''));
        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || $name === '') {
            throw new RuntimeException('platform_mail_sender_configuration_invalid');
        }

        if ($driver === 'smtp') {
            if (empty($config['host']) || (int) ($config['port'] ?? 0) < 1) {
                throw new RuntimeException('platform_mail_smtp_configuration_invalid');
            }
            $mailer = Mail::build([
                'transport' => 'smtp',
                'scheme' => ($config['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : null,
                'host' => $config['host'],
                'port' => (int) $config['port'],
                'username' => ($config['username'] ?? '') !== '' ? $config['username'] : null,
                'password' => ($config['password'] ?? '') !== '' ? $config['password'] : null,
                'timeout' => 10,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
            ]);
            $mailer->html($html, function ($message) use ($to, $subject, $from, $name, $text): void {
                $message->to($to)->from($from, $name)->subject($subject)->text($text);
            });
            return;
        }

        if ($driver === 'postmark') {
            $token = (string) ($config['server_token'] ?? '');
            $stream = (string) ($config['message_stream'] ?? '');
            if ($token === '' || $stream === '') {
                throw new RuntimeException('platform_mail_postmark_configuration_invalid');
            }
            Http::acceptJson()
                ->withHeaders(['X-Postmark-Server-Token' => $token])
                ->connectTimeout(3)
                ->timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->post('https://api.postmarkapp.com/email', [
                    'From' => $name.' <'.$from.'>',
                    'To' => $to,
                    'Subject' => $subject,
                    'HtmlBody' => $html,
                    'TextBody' => $text,
                    'MessageStream' => $stream,
                ])->throw();
            return;
        }

        throw new RuntimeException('platform_mail_driver_invalid');
    }
}

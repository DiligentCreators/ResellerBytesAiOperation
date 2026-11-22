<?php

namespace App\Services\Alerts\Drivers;

use App\Models\TenantAlertChannel;
use App\Services\Alerts\AlertMessage;
use App\Services\Mail\CentralOutboundMailer;
use RuntimeException;

final class EmailAlertDriver implements AlertChannelDriver
{
    public function __construct(private readonly CentralOutboundMailer $mailer) {}

    public function send(TenantAlertChannel $channel, string $alertKey, AlertMessage $message): void
    {
        $to = trim((string) $channel->target);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('alert_email_invalid');
        }

        $text = $message->plainText();
        $html = nl2br(e($text));

        $this->mailer->send($to, $message->title, $html, $text);
    }
}

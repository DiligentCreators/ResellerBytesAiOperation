<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class PlatformMailTransport extends AbstractTransport
{
    public function __construct(private readonly CentralOutboundMailer $mailer)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new \RuntimeException('platform_mail_message_type_unsupported');
        }

        $html = (string) ($email->getHtmlBody() ?? '');
        $text = (string) ($email->getTextBody() ?? strip_tags($html));
        foreach ($email->getTo() as $recipient) {
            $this->mailer->send($recipient->getAddress(), (string) $email->getSubject(), $html, $text);
        }
    }

    public function __toString(): string
    {
        return 'platform';
    }
}

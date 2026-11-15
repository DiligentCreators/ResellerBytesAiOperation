<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

final class OperationalAlertMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $bodyText,
    ) {}

    public function build(): static
    {
        return $this
            ->subject('[ResellerBytes AI Operations] '.$this->severity.': '.$this->code)
            ->html(nl2br(e($this->bodyText)));
    }
}

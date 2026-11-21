<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class EmailSendingFailedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'email.sending_failed';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $mail = $this->whmcsBlock($payload, 'mail');
        if ($mail === null || ! is_numeric($mail['sending_failed_24h'] ?? null)) {
            return null;
        }

        $count = (int) $mail['sending_failed_24h'];
        $threshold = $this->intSetting('monitoring.mail_sending_failure_threshold_24h', 1);
        if ($count < $threshold) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $count,
            ['whmcs.mail.sending_failed_24h' => $count],
        );
    }
}

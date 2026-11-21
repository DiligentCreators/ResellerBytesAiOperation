<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class EmailPipingFailedRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'email.piping_failed';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $mail = $this->whmcsBlock($payload, 'mail');
        if ($mail === null || ! is_numeric($mail['piping_failed_7d'] ?? null)) {
            return null;
        }

        $count = (int) $mail['piping_failed_7d'];
        $threshold = $this->intSetting('monitoring.mail_piping_failure_threshold_7d', 1);
        if ($count < $threshold) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            $count,
            ['whmcs.mail.piping_failed_7d' => $count],
        );
    }
}

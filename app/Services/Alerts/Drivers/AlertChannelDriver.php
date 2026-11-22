<?php

namespace App\Services\Alerts\Drivers;

use App\Models\TenantAlertChannel;
use App\Services\Alerts\AlertMessage;

interface AlertChannelDriver
{
    /**
     * Deliver the alert. Throw on failure; the dispatcher records quiet failures.
     */
    public function send(TenantAlertChannel $channel, string $alertKey, AlertMessage $message): void;
}

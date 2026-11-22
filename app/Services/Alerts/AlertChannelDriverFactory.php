<?php

namespace App\Services\Alerts;

use App\Models\TenantAlertChannel;
use App\Services\Alerts\Drivers\AlertChannelDriver;
use App\Services\Alerts\Drivers\DiscordAlertDriver;
use App\Services\Alerts\Drivers\EmailAlertDriver;
use App\Services\Alerts\Drivers\SlackAlertDriver;
use App\Services\Alerts\Drivers\WebhookAlertDriver;
use InvalidArgumentException;

final class AlertChannelDriverFactory
{
    public function __construct(
        private readonly EmailAlertDriver $email,
        private readonly SlackAlertDriver $slack,
        private readonly DiscordAlertDriver $discord,
        private readonly WebhookAlertDriver $webhook,
    ) {}

    public function make(string $type): AlertChannelDriver
    {
        return match ($type) {
            TenantAlertChannel::TYPE_EMAIL => $this->email,
            TenantAlertChannel::TYPE_SLACK => $this->slack,
            TenantAlertChannel::TYPE_DISCORD => $this->discord,
            TenantAlertChannel::TYPE_WEBHOOK => $this->webhook,
            default => throw new InvalidArgumentException('Unsupported alert channel type.'),
        };
    }
}

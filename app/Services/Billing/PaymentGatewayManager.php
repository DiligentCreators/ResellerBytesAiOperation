<?php

namespace App\Services\Billing;

use App\Billing\Sandbox\SandboxPaymentGateway;
use App\Contracts\Billing\PaymentGateway;
use InvalidArgumentException;
use RuntimeException;

class PaymentGatewayManager
{
    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('billing.default_gateway', 'sandbox');

        $gateway = match ($name) {
            'sandbox' => app(SandboxPaymentGateway::class),
            default => throw new InvalidArgumentException('billing_gateway_unknown'),
        };

        if (! $gateway instanceof PaymentGateway) {
            throw new RuntimeException('billing_gateway_invalid');
        }

        $gateway->assertUsable();

        return $gateway;
    }
}

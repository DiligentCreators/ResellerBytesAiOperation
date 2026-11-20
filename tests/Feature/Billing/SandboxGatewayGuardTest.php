<?php

use App\Billing\Sandbox\SandboxPaymentGateway;
use RuntimeException;

it('blocks the sandbox gateway in production unless explicitly allowed', function () {
    $previous = app()->environment();

    config([
        'billing.sandbox.enabled' => true,
        'billing.sandbox.allow_in_production' => false,
    ]);

    try {
        app()->detectEnvironment(fn () => 'production');

        expect(fn () => app(SandboxPaymentGateway::class)->assertUsable())
            ->toThrow(RuntimeException::class, 'billing_sandbox_blocked_in_production');
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }
});

it('allows sandbox when explicitly enabled for production', function () {
    $previous = app()->environment();

    config([
        'billing.sandbox.enabled' => true,
        'billing.sandbox.allow_in_production' => true,
    ]);

    try {
        app()->detectEnvironment(fn () => 'production');
        app(SandboxPaymentGateway::class)->assertUsable();
        expect(true)->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }
});

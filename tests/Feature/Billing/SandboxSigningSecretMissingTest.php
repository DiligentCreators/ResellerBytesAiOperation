<?php

use App\Billing\Sandbox\SandboxBillingProviderAdapter;
use RuntimeException;

it('fails closed when the sandbox signing secret is missing', function () {
    config()->set('billing.sandbox.signing_secret', '');

    expect(fn () => app(SandboxBillingProviderAdapter::class)->verifyAndNormalize('{}', [
        'X-Sandbox-Signature' => 'not-a-real-signature',
        'X-Sandbox-Timestamp' => (string) now()->timestamp,
    ]))->toThrow(RuntimeException::class, 'billing_sandbox_signing_secret_missing');
});

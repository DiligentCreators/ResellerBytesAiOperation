<?php

use App\Billing\Sandbox\SandboxBillingProviderAdapter;
use App\Billing\Sandbox\SandboxSigningSecret;
use App\Models\PlatformSetting;
use App\Services\PlatformSettings;
use RuntimeException;

it('fails closed in production when the sandbox signing secret is missing', function () {
    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    config()->set('billing.sandbox.signing_secret', null);
    PlatformSetting::query()->where('key', SandboxSigningSecret::SETTING_KEY)->delete();
    app(PlatformSettings::class)->invalidateCache();

    try {
        expect(fn () => app(SandboxBillingProviderAdapter::class)->verifyAndNormalize('{}', [
            'X-Sandbox-Signature' => 'not-a-real-signature',
            'X-Sandbox-Timestamp' => (string) now()->timestamp,
        ]))->toThrow(RuntimeException::class, 'billing_sandbox_signing_secret_missing');
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }
});

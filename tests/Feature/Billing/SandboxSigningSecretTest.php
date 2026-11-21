<?php

use App\Billing\Sandbox\SandboxSigningSecret;
use App\Models\PlatformSetting;
use App\Services\PlatformSettings;
use RuntimeException;

it('defaults the billing sandbox signing secret config to null with no literal fallback', function () {
    $source = (string) file_get_contents(config_path('billing.php'));

    expect($source)->toContain("env('BILLING_SANDBOX_SIGNING_SECRET')")
        ->and($source)->not->toContain('local-sandbox-billing-secret')
        ->and($source)->not->toMatch("/env\\('BILLING_SANDBOX_SIGNING_SECRET'\\s*,/");
});

it('auto-generates and reuses an encrypted signing secret outside production', function () {
    config()->set('billing.sandbox.signing_secret', null);
    PlatformSetting::query()->where('key', SandboxSigningSecret::SETTING_KEY)->delete();
    app(PlatformSettings::class)->invalidateCache();
    app()->forgetInstance(SandboxSigningSecret::class);

    $first = app(SandboxSigningSecret::class)->resolve();
    $second = app(SandboxSigningSecret::class)->resolve();

    expect($first)->toBeString()
        ->and(strlen($first))->toBeGreaterThanOrEqual(32)
        ->and($second)->toBe($first);

    $row = PlatformSetting::query()->where('key', SandboxSigningSecret::SETTING_KEY)->first();
    expect($row)->not->toBeNull()
        ->and($row->is_secret)->toBeTrue()
        ->and($row->getAttributes()['value'])->not->toBe($first)
        ->and($row->decodedValue())->toBe($first);
});

it('refuses to resolve a signing secret in production when missing', function () {
    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    config()->set('billing.sandbox.signing_secret', null);
    PlatformSetting::query()->where('key', SandboxSigningSecret::SETTING_KEY)->delete();
    app(PlatformSettings::class)->invalidateCache();
    app()->forgetInstance(SandboxSigningSecret::class);

    try {
        expect(fn () => app(SandboxSigningSecret::class)->resolve())
            ->toThrow(RuntimeException::class, 'billing_sandbox_signing_secret_missing');
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }
});

it('registers the sandbox signing secret in the platform settings registry', function () {
    $presentation = app(PlatformSettings::class)->presentation();

    $billing = $presentation['settings']['billing'] ?? [];
    $secretRow = collect($billing)->firstWhere('key', SandboxSigningSecret::SETTING_KEY);

    expect($secretRow)->not->toBeNull()
        ->and($secretRow['type'])->toBe('secret')
        ->and($secretRow)->toHaveKey('is_set')
        ->and($secretRow['value'])->toBeNull();
});

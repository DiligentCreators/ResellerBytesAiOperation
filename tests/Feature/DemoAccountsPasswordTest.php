<?php

use App\Models\PlatformSetting;
use App\Services\PlatformSettings;
use App\Support\Operations\DemoAccountsPassword;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

it('defaults the demo accounts password config to null with no literal fallback', function () {
    $source = (string) file_get_contents(config_path('operations.php'));

    expect($source)->toContain("env('DEMO_ACCOUNTS_PASSWORD')")
        ->and($source)->not->toContain('Local-Test-Passw0rd!')
        ->and($source)->not->toMatch("/env\\('DEMO_ACCOUNTS_PASSWORD'\\s*,/");
});

it('keeps DEMO_ACCOUNTS_PASSWORD empty in .env.example', function () {
    $source = (string) file_get_contents(base_path('.env.example'));

    expect($source)->toMatch('/^DEMO_ACCOUNTS_PASSWORD=$/m')
        ->and($source)->not->toMatch('/^DEMO_ACCOUNTS_PASSWORD=.+$/m');
});

it('auto-generates and reuses an encrypted demo password outside production', function () {
    config()->set('operations.demo_accounts.password', null);
    PlatformSetting::query()->where('key', DemoAccountsPassword::SETTING_KEY)->delete();
    app(PlatformSettings::class)->invalidateCache();
    app()->forgetInstance(DemoAccountsPassword::class);

    $first = app(DemoAccountsPassword::class)->resolve();
    $second = app(DemoAccountsPassword::class)->resolve();

    expect($first)->toBeString()
        ->and(strlen($first))->toBeGreaterThanOrEqual(32)
        ->and($second)->toBe($first);

    $row = PlatformSetting::query()->where('key', DemoAccountsPassword::SETTING_KEY)->first();
    expect($row)->not->toBeNull()
        ->and($row->is_secret)->toBeTrue()
        ->and($row->getAttributes()['value'])->not->toBe($first)
        ->and($row->decodedValue())->toBe($first);
});

it('refuses to resolve a demo password in production when missing', function () {
    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    config()->set('operations.demo_accounts.password', null);
    PlatformSetting::query()->where('key', DemoAccountsPassword::SETTING_KEY)->delete();
    app(PlatformSettings::class)->invalidateCache();
    app()->forgetInstance(DemoAccountsPassword::class);

    try {
        expect(fn () => app(DemoAccountsPassword::class)->resolve())
            ->toThrow(RuntimeException::class, 'operations_demo_accounts_password_missing');
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }
});

it('registers the demo accounts password in the platform settings registry', function () {
    $presentation = app(PlatformSettings::class)->presentation();

    $security = $presentation['settings']['security'] ?? [];
    $secretRow = collect($security)->firstWhere('key', DemoAccountsPassword::SETTING_KEY);

    expect($secretRow)->not->toBeNull()
        ->and($secretRow['type'])->toBe('secret')
        ->and($secretRow)->toHaveKey('is_set')
        ->and($secretRow['value'])->toBeNull();
});

it('writes the demo password to --output without printing it', function () {
    config()->set('operations.demo_accounts.password', null);
    PlatformSetting::query()->where('key', DemoAccountsPassword::SETTING_KEY)->delete();
    app(PlatformSettings::class)->invalidateCache();
    app()->forgetInstance(DemoAccountsPassword::class);

    $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'demo-password-'.uniqid('', true).'.txt';

    $exitCode = Artisan::call('demo:accounts-password', ['--output' => $output]);
    $console = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($console)->toContain('Demo accounts password written.')
        ->and($console)->toContain('Output: '.$output)
        ->and(file_exists($output))->toBeTrue();

    $password = trim((string) file_get_contents($output));
    expect(strlen($password))->toBeGreaterThanOrEqual(32)
        ->and($console)->not->toContain($password);

    @unlink($output);
});

it('refuses demo:accounts-password in production', function () {
    $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'demo-password-'.uniqid('', true).'.txt';

    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('demo:accounts-password', ['--output' => $output])
            ->expectsOutputToContain('refuses to run in production')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn () => $previous);
    }

    expect(file_exists($output))->toBeFalse();
});

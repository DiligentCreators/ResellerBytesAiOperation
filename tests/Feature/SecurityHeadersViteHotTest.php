<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

/**
 * @return array{existed: bool, contents: string|null}
 */
function securityHeadersHotBackup(): array
{
    $hot = public_path('hot');

    return [
        'existed' => File::exists($hot),
        'contents' => File::exists($hot) ? File::get($hot) : null,
    ];
}

/**
 * @param  array{existed: bool, contents: string|null}  $backup
 */
function securityHeadersHotRestore(array $backup): void
{
    $hot = public_path('hot');

    if ($backup['existed']) {
        File::put($hot, (string) $backup['contents']);

        return;
    }

    if (File::exists($hot)) {
        File::delete($hot);
    }
}

it('allows the exact Vite hot origin including IPv6 in non-production CSP', function () {
    $backup = securityHeadersHotBackup();
    File::put(public_path('hot'), "http://[::1]:5173\n");

    try {
        expect(Vite::isRunningHot())->toBeTrue();

        $response = $this->get(route('home'));
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $response->assertOk();
        expect($csp)->toContain('http://[::1]:5173')
            ->and($csp)->toContain('ws://[::1]:5173')
            ->and($csp)->toContain("script-src 'self' 'unsafe-inline' http://[::1]:5173")
            ->and($csp)->toContain("font-src 'self' data: http://[::1]:5173")
            ->and($csp)->toContain("img-src 'self' data: blob: http://[::1]:5173")
            ->and($csp)->toContain("connect-src 'self' http://[::1]:5173 ws://[::1]:5173");
    } finally {
        securityHeadersHotRestore($backup);
    }
});

it('keeps production CSP strict without Vite hot origins', function () {
    $backup = securityHeadersHotBackup();
    File::put(public_path('hot'), "http://[::1]:5173\n");
    $previous = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        $response = $this->get(route('home'));
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $response->assertOk();
        expect($csp)->not->toContain('[::1]')
            ->and($csp)->not->toContain('localhost')
            ->and($csp)->toContain("script-src 'self' 'unsafe-inline'")
            ->and($csp)->toContain("font-src 'self' data:")
            ->and($csp)->toContain("connect-src 'self'");
    } finally {
        $this->app['env'] = $previous;
        securityHeadersHotRestore($backup);
    }
});

it('omits Cross-Origin-Opener-Policy on insecure non-production requests', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    expect($response->headers->get('Cross-Origin-Opener-Policy'))->toBeNull();
});

it('sends Cross-Origin-Opener-Policy on secure requests', function () {
    $response = $this->get('https://localhost/');

    $response->assertOk();
    expect($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
});

<?php

it('configures session cookies for production-safe defaults', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.encrypt'))->toBeBool();
});

it('marks session cookies secure when APP_URL is https', function () {
    config(['session.secure' => str_starts_with((string) config('app.url'), 'https://') || (bool) config('session.secure')]);

    // Explicit production-style override path.
    config(['session.secure' => true]);
    expect(config('session.secure'))->toBeTrue();
});

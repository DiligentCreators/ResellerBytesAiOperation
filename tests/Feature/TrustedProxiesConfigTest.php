<?php

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

it('documents TRUSTED_PROXIES in the example environment file', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('TRUSTED_PROXIES=')
        ->and(config('app.trusted_proxies'))->toBeString();
});

it('trusts configured proxies so client IP comes from forwarded headers', function () {
    TrustProxies::flushState();
    TrustProxies::at(['127.0.0.1']);

    $request = Request::create('http://localhost/', 'GET', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $middleware = new TrustProxies;
    $middleware->handle($request, fn (Request $next) => response('ok'));

    expect($request->ip())->toBe('203.0.113.50')
        ->and($request->isSecure())->toBeTrue();

    TrustProxies::flushState();
});

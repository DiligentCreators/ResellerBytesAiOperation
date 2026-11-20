<?php

it('sends security headers on web responses', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});

it('sends HSTS when the request is secure', function () {
    $response = $this->get('https://localhost/');

    $response->assertOk();
    expect($response->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
});

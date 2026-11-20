<?php

it('returns not found for unknown routes', function () {
    $this->get('/definitely-missing-c4-page-'.uniqid())
        ->assertNotFound();
});

it('serves blade fallback markup for 404 outside inertia when available', function () {
    $path = resource_path('views/errors/404.blade.php');

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toContain('Page not found');
});

it('defines branded inertia error page component', function () {
    expect(file_exists(resource_path('js/pages/errors/error.tsx')))->toBeTrue();
});

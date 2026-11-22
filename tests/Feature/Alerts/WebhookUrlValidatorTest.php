<?php

use App\Support\Alerts\WebhookUrlValidator;

it('requires https webhook urls', function () {
    $validator = app(WebhookUrlValidator::class);

    expect($validator->isValid('http://hooks.example.test/alerts', allowPrivate: false))->toBeFalse()
        ->and($validator->isValid('https://hooks.example.test/alerts', allowPrivate: false))->toBeTrue();
});

it('blocks private and localhost targets to prevent SSRF', function () {
    $validator = app(WebhookUrlValidator::class);

    expect($validator->isValid('https://127.0.0.1/hook', allowPrivate: false))->toBeFalse()
        ->and($validator->isValid('https://localhost/hook', allowPrivate: false))->toBeFalse()
        ->and($validator->isValid('https://10.0.0.5/hook', allowPrivate: false))->toBeFalse()
        ->and($validator->isValid('https://192.168.1.10/hook', allowPrivate: false))->toBeFalse()
        ->and($validator->isValid('https://169.254.169.254/latest/meta-data', allowPrivate: false))->toBeFalse()
        ->and($validator->validate('https://127.0.0.1/hook')['message'])->toContain('private or local');
});

<?php

use Illuminate\\Support\\Str;

test('rejects timestamps that are not ten-digit Unix seconds', function () {
    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_RB_INTEGRATION' => (string) Str::uuid(),
        'HTTP_X_RB_TIMESTAMP' => '00001700000000',
        'HTTP_X_RB_NONCE' => Str::random(64),
        'HTTP_X_RB_SIGNATURE' => str_repeat('a', 64),
    ], '{}')->assertUnauthorized();
});

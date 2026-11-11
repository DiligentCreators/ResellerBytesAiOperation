<?php

test('registers the expired nonce cleanup in the Laravel scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('whmcs:nonces:prune')
        ->assertSuccessful();
});

<?php

use App\\Models\\IntegrationRequestNonce;
use App\\Models\\WhmcsIntegration;
use Illuminate\\Support\\Carbon;

test('prunes expired integration nonces but preserves active replay protections', function () {
    $integration = WhmcsIntegration::create([
        'name' => 'Nonce cleanup test',
        'status' => 'active',
        'secret' => str_repeat('a', 64),
        'secret_version' => 1,
    ]);

    IntegrationRequestNonce::create([
        'integration_id' => $integration->id,
        'nonce' => 'expired-nonce-value-123456789012',
        'expires_at' => Carbon::now()->subMinute(),
        'created_at' => Carbon::now()->subMinutes(11),
    ]);

    IntegrationRequestNonce::create([
        'integration_id' => $integration->id,
        'nonce' => 'active-nonce-value-1234567890123',
        'expires_at' => Carbon::now()->addMinutes(5),
        'created_at' => Carbon::now(),
    ]);

    $this->artisan('whmcs:nonces:prune')
        ->expectsOutputToContain('Deleted 1 expired WHMCS integration nonce(s).')
        ->assertSuccessful();

    expect(IntegrationRequestNonce::count())->toBe(1)
        ->and(IntegrationRequestNonce::query()->value('nonce'))
        ->toBe('active-nonce-value-1234567890123');
});

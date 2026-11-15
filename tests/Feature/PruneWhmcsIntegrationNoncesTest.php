<?php

use App\Models\WhmcsIntegration;
use Illuminate\Support\Facades\DB;

it('prunes only expired WHMCS request nonces and preserves active replay guards', function () {
    $integration = WhmcsIntegration::query()->create([
        'name' => 'Nonce pruning test integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);

    $expiredNonce = str_repeat('e', 32);
    $activeNonce = str_repeat('a', 32);

    DB::table('integration_request_nonces')->insert([
        [
            'integration_id' => $integration->id,
            'nonce' => $expiredNonce,
            'expires_at' => now()->subSecond(),
            'created_at' => now()->subMinutes(11),
        ],
        [
            'integration_id' => $integration->id,
            'nonce' => $activeNonce,
            'expires_at' => now()->addMinute(),
            'created_at' => now(),
        ],
    ]);

    $this->artisan('whmcs:nonces:prune')
        ->expectsOutput('Deleted 1 expired WHMCS integration nonce(s).')
        ->assertSuccessful();

    expect(DB::table('integration_request_nonces')
        ->where('integration_id', $integration->id)
        ->where('nonce', $expiredNonce)
        ->exists())->toBeFalse()
        ->and(DB::table('integration_request_nonces')
            ->where('integration_id', $integration->id)
            ->where('nonce', $activeNonce)
            ->exists())->toBeTrue();
});

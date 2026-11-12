<?php

use App\Models\User;

function configureFingerprintTestKeys(): string
{
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);

    config([
        'services.whmcs_addon.signing_secret_key' => base64_encode($secretKey),
        'services.whmcs_addon.signing_public_key' => base64_encode($publicKey),
    ]);

    return hash('sha256', $publicKey);
}

it('requires an authenticated verified operator to view the addon signing fingerprint', function () {
    $this->get('/whmcs-addon/signing-key-fingerprint')
        ->assertRedirect('/login');
});

it('exposes the trusted signing fingerprint separately from the downloaded archive', function () {
    if (! function_exists('sodium_crypto_sign_detached')) {
        $this->markTestSkipped('The PHP Sodium extension is required.');
    }

    $fingerprint = configureFingerprintTestKeys();
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/whmcs-addon/signing-key-fingerprint')
        ->assertOk()
        ->assertHeader('Cache-Control', 'private, no-store, max-age=0')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee($fingerprint, false);
});

it('does not expose a fingerprint when the signing key is unavailable', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/whmcs-addon/signing-key-fingerprint')
        ->assertServiceUnavailable();
});

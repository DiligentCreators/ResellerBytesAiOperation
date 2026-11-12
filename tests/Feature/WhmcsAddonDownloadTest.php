<?php

use App\Models\User;
use App\Support\WhmcsAddonPackageSigner;

function configureAddonSigningKeys(): array
{
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);

    config([
        'services.whmcs_addon.signing_secret_key' => base64_encode($secretKey),
        'services.whmcs_addon.signing_public_key' => base64_encode($publicKey),
    ]);

    return [$secretKey, $publicKey];
}

it('requires an authenticated verified user to download the WHMCS addon', function () {
    $this->get('/whmcs-addon/download')
        ->assertRedirect('/login');
});

it('downloads an addon archive with a verifiable Ed25519 signature and expected module structure', function () {
    if (! class_exists(ZipArchive::class) || ! function_exists('sodium_crypto_sign_verify_detached')) {
        $this->markTestSkipped('The PHP ZIP and Sodium extensions are required.');
    }

    [, $publicKey] = configureAddonSigningKeys();

    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/whmcs-addon/download');

    $response->assertDownload('resellerbytes-ai-ops-whmcs-v1.0.0.zip');
    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');

    $fingerprint = hash('sha256', $publicKey);
    $response->assertHeader('X-Addon-Signing-Key-SHA256', $fingerprint);

    $file = $response->baseResponse->getFile();
    expect($file)->not->toBeNull();

    $archive = new ZipArchive;
    expect($archive->open($file->getPathname()))->toBeTrue();

    expect($archive->locateName('resellerbytes_ai_ops/resellerbytes_ai_ops.php'))->not->toBeFalse();
    expect($archive->locateName('resellerbytes_ai_ops/lib/CredentialsFile.php'))->not->toBeFalse();
    expect($archive->locateName('INSTALLATION.txt'))->not->toBeFalse();
    expect($archive->locateName('VERSION.txt'))->not->toBeFalse();
    expect($archive->locateName('SHA256SUMS.txt'))->not->toBeFalse();
    expect($archive->locateName('SIGNATURE.ed25519'))->not->toBeFalse();
    expect($archive->locateName('SIGNING-PUBLIC-KEY.txt'))->not->toBeFalse();
    expect($archive->locateName('SIGNING-KEY-FINGERPRINT.txt'))->not->toBeFalse();
    expect($archive->locateName('configuration.php'))->toBeFalse();

    $manifest = trim($archive->getFromName('SHA256SUMS.txt'));
    $version = trim($archive->getFromName('VERSION.txt'));
    $signature = base64_decode(trim($archive->getFromName('SIGNATURE.ed25519')), true);
    $packagedPublicKey = base64_decode(trim($archive->getFromName('SIGNING-PUBLIC-KEY.txt')), true);
    $packagedFingerprint = trim($archive->getFromName('SIGNING-KEY-FINGERPRINT.txt'));

    $archive->close();

    expect($packagedPublicKey)->toBe($publicKey);
    expect($packagedFingerprint)->toBe($fingerprint);
    expect($signature)->not->toBeFalse();

    $signedPayload = "resellerbytes-ai-ops-package-v1\nversion={$version}\n{$manifest}\n";

    expect(sodium_crypto_sign_verify_detached($signature, $signedPayload, $packagedPublicKey))->toBeTrue();
    expect(sodium_crypto_sign_verify_detached($signature, $signedPayload.'tampered', $packagedPublicKey))->toBeFalse();
});

it('does not make the addon download available without a valid signing key pair', function () {
    config([
        'services.whmcs_addon.signing_secret_key' => null,
        'services.whmcs_addon.signing_public_key' => null,
    ]);

    $user = User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/whmcs-addon/download')
        ->assertServiceUnavailable();
});

it('rejects a mismatched addon signing key pair', function () {
    $keyPair = sodium_crypto_sign_keypair();
    $otherPair = sodium_crypto_sign_keypair();

    config([
        'services.whmcs_addon.signing_secret_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
        'services.whmcs_addon.signing_public_key' => base64_encode(sodium_crypto_sign_publickey($otherPair)),
    ]);

    expect(app(WhmcsAddonPackageSigner::class)->isConfigured())->toBeFalse();
});

it('does not expose the WHMCS addon download to unverified users', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)
        ->get('/whmcs-addon/download')
        ->assertRedirect(route('verification.notice'));
});

it('does not expose the WHMCS addon download before two-factor authentication is confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/whmcs-addon/download')
        ->assertRedirect(route('security.edit'));
});

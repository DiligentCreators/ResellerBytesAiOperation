<?php

use App\Models\IntegrationRequestNonce;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;

function signedWhmcsSnapshotRequest(
    string $integrationId,
    string $secret,
    string $nonce,
    ?string $signatureOverride = null,
) {
    $payload = [
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'payload' => [
            'system' => [
                'whmcs_version' => '8.13.7',
                'web_php_version' => '8.2.0',
                'error_display_disabled' => true,
                'configuration_file_writable' => false,
            ],
            'php' => [
                'memory_limit' => '256M',
                'extensions' => [],
            ],
            'collector' => [
                'capabilities' => [],
                'limitations' => [],
            ],
        ],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) now()->timestamp;
    $path = '/api/v1/whmcs/diagnostic-snapshots';
    $canonical = implode("\n", [
        'POST',
        $path,
        $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);
    $signature = $signatureOverride ?? hash_hmac('sha256', $canonical, $secret);

    return test()->call('POST', $path, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_RB_INTEGRATION' => $integrationId,
        'HTTP_X_RB_TIMESTAMP' => $timestamp,
        'HTTP_X_RB_NONCE' => $nonce,
        'HTTP_X_RB_SIGNATURE' => $signature,
    ], $body);
}

function createWhmcsAuthenticationFixture(string $status = 'active'): WhmcsIntegration
{
    return WhmcsIntegration::query()->create([
        'name' => 'Authentication test integration',
        'status' => $status,
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
}

it('accepts a valid signed WHMCS snapshot once and rejects nonce replay', function () {
    config(['services.openai.key' => null]);
    $integration = createWhmcsAuthenticationFixture();
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

    $first = signedWhmcsSnapshotRequest($integration->id, $integration->secret, $nonce);
    $first->assertAccepted();

    expect(IntegrationRequestNonce::query()
        ->where('integration_id', $integration->id)
        ->where('nonce', $nonce)
        ->count())->toBe(1);

    $replay = signedWhmcsSnapshotRequest($integration->id, $integration->secret, $nonce);
    $replay->assertStatus(409)
        ->assertJson(['message' => 'Request already used.']);
});

it('rejects a signed request after its WHMCS integration has been revoked', function () {
    $integration = createWhmcsAuthenticationFixture('revoked');
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

    signedWhmcsSnapshotRequest($integration->id, $integration->secret, $nonce)
        ->assertUnauthorized();

    expect(IntegrationRequestNonce::query()
        ->where('integration_id', $integration->id)
        ->where('nonce', $nonce)
        ->exists())->toBeFalse();
});

it('rejects a WHMCS request with an invalid HMAC signature', function () {
    $integration = createWhmcsAuthenticationFixture();
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

    signedWhmcsSnapshotRequest($integration->id, $integration->secret, $nonce, str_repeat('0', 64))
        ->assertUnauthorized();

    expect(IntegrationRequestNonce::query()
        ->where('integration_id', $integration->id)
        ->where('nonce', $nonce)
        ->exists())->toBeFalse();
});

<?php

use App\Models\AuditEvent;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;

function makeSignedHeartbeat(WhmcsIntegration $integration, array $overrides = [], ?string $nonce = null, ?int $timestamp = null): array
{
    $payload = array_replace_recursive([
        'addon_version' => '1.2.0',
        'whmcs_version' => '8.13.7',
        'cli_php_version' => '8.1.34',
        'sent_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'cron_kind' => 'system',
    ], $overrides);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp ??= now()->timestamp;
    $nonce ??= Str::random(64);
    $path = '/api/v1/whmcs/heartbeats';
    $canonical = implode(chr(10), [
        'POST',
        $path,
        (string) $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);

    return [
        'body' => $body,
        'headers' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => hash_hmac('sha256', $canonical, $integration->secret),
        ],
    ];
}

function createHeartbeatIntegration(array $overrides = []): WhmcsIntegration
{
    return WhmcsIntegration::create(array_merge([
        'name' => 'Heartbeat install',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ], $overrides));
}

test('accepts a signed system heartbeat and stores heartbeat metadata', function () {
    $integration = createHeartbeatIntegration();
    $signed = makeSignedHeartbeat($integration, [
        'cli_php_version' => '8.2.12',
        'cron_kind' => 'system',
    ]);

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted()
        ->assertJsonPath('data.accepted', true);

    $fresh = $integration->fresh();
    expect($fresh->last_heartbeat_at)->not->toBeNull()
        ->and($fresh->cron_php_version)->toBe('8.2.12')
        ->and($fresh->last_cron_at)->toBeNull()
        ->and($fresh->addon_version)->toBe('1.2.0')
        ->and(AuditEvent::where('event_type', 'whmcs_heartbeat.accepted')->count())->toBe(1);
});

test('daily heartbeat also updates last_cron_at', function () {
    $integration = createHeartbeatIntegration();
    $signed = makeSignedHeartbeat($integration, ['cron_kind' => 'daily']);

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $fresh = $integration->fresh();
    expect($fresh->last_cron_at)->not->toBeNull()
        ->and($fresh->last_heartbeat_at?->equalTo($fresh->last_cron_at))->toBeTrue();
});

test('rejects heartbeat payloads with unsupported fields', function () {
    $integration = createHeartbeatIntegration();
    $signed = makeSignedHeartbeat($integration, ['customer_email' => 'nope@example.test']);

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertUnprocessable();
});

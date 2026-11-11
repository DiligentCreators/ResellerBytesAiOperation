<?php

use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use App\Models\IntegrationRequestNonce;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;

function makeSignedSnapshot(WhmcsIntegration $integration, array $overrides = [], ?string $nonce = null, ?int $timestamp = null): array
{
    $payload = array_replace_recursive([
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'collected_at' => now()->toIso8601String(),
        'payload' => [
            'system' => [
                'whmcs_version' => '8.13.7',
                'web_php_version' => '8.1.34',
                'cron_php_version' => '8.3.36',
            ],
            'warnings' => [
                ['code' => 'cron_php_mismatch', 'severity' => 'warning'],
            ],
        ],
    ], $overrides);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp ??= now()->timestamp;
    $nonce ??= Str::random(64);
    $path = '/api/v1/whmcs/diagnostic-snapshots';
    $canonical = implode("\n", [
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

function createSignedTestIntegration(array $overrides = []): WhmcsIntegration
{
    return WhmcsIntegration::create(array_merge([
        'name' => 'WHMCS API test',
        'status' => 'active',
        'secret' => 'integration-secret-for-feature-tests',
        'secret_version' => 1,
    ], $overrides));
}

test('accepts a valid signed diagnostic snapshot and records an audit event', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration);

    $response = $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    );

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.duplicate', false);

    expect(DiagnosticRun::count())->toBe(1)
        ->and(AuditEvent::where('event_type', 'diagnostic_snapshot.accepted')->count())->toBe(1)
        ->and($integration->fresh()->last_seen_at)->not->toBeNull();
});

test('rejects an invalid signature without consuming a nonce', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration);
    $signed['headers']['HTTP_X_RB_SIGNATURE'] = str_repeat('0', 64);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    )->assertUnauthorized();

    expect(DiagnosticRun::count())->toBe(0)
        ->and(IntegrationRequestNonce::count())->toBe(0);
});

test('rejects stale signed requests', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration, [], null, now()->subMinutes(10)->timestamp);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    )->assertUnauthorized();

    expect(DiagnosticRun::count())->toBe(0);
});

test('rejects a nonce that has already been used', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertStatus(409);

    expect(DiagnosticRun::count())->toBe(1);
});

test('treats a repeated snapshot identifier as idempotent when signed with a fresh nonce', function () {
    $integration = createSignedTestIntegration();
    $snapshotUuid = (string) Str::uuid();
    $overrides = ['snapshot_uuid' => $snapshotUuid];
    $first = makeSignedSnapshot($integration, $overrides);
    $second = makeSignedSnapshot($integration, $overrides);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $first['headers'], $first['body'])
        ->assertAccepted();

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $second['headers'], $second['body'])
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    expect(DiagnosticRun::count())->toBe(1)
        ->and(AuditEvent::where('event_type', 'diagnostic_snapshot.accepted')->count())->toBe(1);
});

test('rejects unknown top-level payload fields', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration, ['customer_email' => 'customer@example.test']);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    )->assertUnprocessable();

    expect(DiagnosticRun::count())->toBe(0);
});

test('rejects snapshots from revoked integrations', function () {
    $integration = createSignedTestIntegration(['status' => 'revoked']);
    $signed = makeSignedSnapshot($integration);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    )->assertUnauthorized();

    expect(DiagnosticRun::count())->toBe(0);
});

test('rejects a repeated snapshot identifier when its payload digest changes', function () {
    $integration = createSignedTestIntegration();
    $snapshotUuid = (string) Str::uuid();
    $first = makeSignedSnapshot($integration, ['snapshot_uuid' => $snapshotUuid]);
    $changed = makeSignedSnapshot($integration, [
        'snapshot_uuid' => $snapshotUuid,
        'payload' => [
            'system' => [
                'whmcs_version' => '8.13.7',
                'web_php_version' => '8.1.34',
                'cron_php_version' => '8.3.36',
                'database_version' => 'MariaDB 10.11.19',
            ],
        ],
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $first['headers'], $first['body'])
        ->assertAccepted();

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $changed['headers'], $changed['body'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Snapshot identifier conflict.');

    expect(DiagnosticRun::count())->toBe(1);
});

test('rejects request bodies larger than the ingestion limit', function () {
    $integration = createSignedTestIntegration();
    $body = str_repeat('x', 262145);
    $timestamp = now()->timestamp;
    $nonce = Str::random(64);
    $canonical = implode("\n", [
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        (string) $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RB_INTEGRATION' => $integration->id,
        'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_RB_NONCE' => $nonce,
        'HTTP_X_RB_SIGNATURE' => hash_hmac('sha256', $canonical, $integration->secret),
    ], $body)->assertBadRequest();

    expect(DiagnosticRun::count())->toBe(0)
        ->and(IntegrationRequestNonce::count())->toBe(0);
});

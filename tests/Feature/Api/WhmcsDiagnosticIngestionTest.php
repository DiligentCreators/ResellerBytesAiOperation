<?php

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use App\Models\IntegrationRequestNonce;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function makeSignedSnapshot(WhmcsIntegration $integration, array $overrides = [], ?string $nonce = null, ?int $timestamp = null): array
{
    $payload = array_replace_recursive([
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'payload' => [
            'system' => [
                'whmcs_version' => '8.13.7',
                'web_php_version' => '8.1.34',
                'database_version' => '10.11.19-MariaDB',
                'web_server' => 'Apache',
                'application_url_configured' => true,
                'error_display_disabled' => true,
                'configuration_file_writable' => true,
            ],
            'php' => [
                'memory_limit' => '512M',
                'extensions' => [
                    'curl' => true,
                    'json' => true,
                ],
            ],
            'warnings' => [
                ['code' => 'configuration_file_writable', 'severity' => 'warning'],
            ],
            'collector' => [
                'capabilities' => [
                    'web_php_version',
                    'whmcs_version',
                    'database_server_version',
                    'php_extension_availability',
                    'php_memory_limit',
                    'configuration_file_writability',
                ],
                'limitations' => [
                    'cron_php_version_not_observable',
                    'module_status_not_collected',
                    'hosting_panel_configuration_not_observable',
                    'ssl_certificate_not_verified',
                ],
            ],
        ],
    ], $overrides);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp ??= now()->timestamp;
    $nonce ??= Str::random(64);
    $path = '/api/v1/whmcs/diagnostic-snapshots';
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
    $overrides = [
        'snapshot_uuid' => $snapshotUuid,
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
    ];
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
                'database_version' => '10.11.19-MariaDB',
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
    $canonical = implode(chr(10), [
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

test('rejects unsigned query parameters on the ingestion endpoint', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots?source=untrusted',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    )->assertBadRequest();

    expect(DiagnosticRun::count())->toBe(0)
        ->and(IntegrationRequestNonce::count())->toBe(0);
});

test('queues AI analysis after a snapshot is committed when an API key is configured', function () {
    config(['services.openai.key' => 'test-api-key']);
    Queue::fake();

    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    )->assertAccepted()
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(AnalyzeDiagnosticRun::class, function (AnalyzeDiagnosticRun $job) {
        return DiagnosticRun::whereKey($job->diagnosticRunId)->exists();
    });
});

test('rejects arbitrary collector metadata instead of forwarding it to AI', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration, [
        'payload' => [
            'collector' => [
                'capabilities' => ['customer@example.test'],
            ],
        ],
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertUnprocessable();

    expect(DiagnosticRun::count())->toBe(0);
});

test('rejects module or cron telemetry until a reviewed collector supports it', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration, [
        'payload' => [
            'modules' => [
                ['identifier' => 'customer_private_value', 'status' => 'enabled'],
            ],
        ],
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertUnprocessable();

    expect(DiagnosticRun::count())->toBe(0);
});

test('accepts nullable configuration-file writability when the collector cannot observe it', function () {
    $integration = createSignedTestIntegration();
    $signed = makeSignedSnapshot($integration);
    $payload = json_decode($signed['body'], true, 32, JSON_THROW_ON_ERROR);
    $payload['payload']['system']['configuration_file_writable'] = null;
    unset($payload['payload']['warnings']);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = $signed['headers']['HTTP_X_RB_TIMESTAMP'];
    $nonce = $signed['headers']['HTTP_X_RB_NONCE'];
    $canonical = implode(chr(10), [
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);
    $signed['headers']['HTTP_X_RB_SIGNATURE'] = hash_hmac('sha256', $canonical, $integration->secret);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $body,
    )->assertAccepted();

    expect(DiagnosticRun::firstOrFail()->payload['system']['configuration_file_writable'])->toBeNull();
});

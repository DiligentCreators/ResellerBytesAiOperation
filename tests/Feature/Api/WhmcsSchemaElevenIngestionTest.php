<?php

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Str;

function schemaElevenBasePayload(): array
{
    return json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/snapshots/schema_1_1_positive.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

function makeSignedSchemaSnapshot(WhmcsIntegration $integration, array $overrides = []): array
{
    $payload = [
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.1',
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'payload' => schemaElevenBasePayload(),
    ];

    if (array_key_exists('payload', $overrides) && is_array($overrides['payload']) && ($overrides['schema_version'] ?? '1.1') === '1.0') {
        $payload['payload'] = $overrides['payload'];
        unset($overrides['payload']);
        $payload = array_replace($payload, $overrides);
    } elseif (array_key_exists('payload', $overrides) && is_array($overrides['payload'])) {
        $payload['payload'] = array_replace_recursive($payload['payload'], $overrides['payload']);
        unset($overrides['payload']);
        $payload = array_replace_recursive($payload, $overrides);
    } else {
        $payload = array_replace_recursive($payload, $overrides);
    }

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $nonce = Str::random(64);
    $canonical = implode(chr(10), [
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
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
        'decoded' => $payload,
    ];
}

function createSchemaElevenIntegration(): WhmcsIntegration
{
    return WhmcsIntegration::create([
        'name' => 'Schema 1.1 API test',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
}

test('accepts a valid schema 1.1 diagnostic snapshot', function () {
    $integration = createSchemaElevenIntegration();
    $signed = makeSignedSchemaSnapshot($integration);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $run = DiagnosticRun::query()->sole();
    expect($run->schema_version)->toBe('1.1')
        ->and($run->payload)->toHaveKeys(['security', 'whmcs', 'host']);
});

test('still accepts schema 1.0 snapshots unchanged', function () {
    $integration = createSchemaElevenIntegration();
    $payload = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/snapshots/schema_1_0_clean.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $signed = makeSignedSchemaSnapshot($integration, [
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'payload' => $payload,
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    expect(DiagnosticRun::query()->sole()->schema_version)->toBe('1.0');
});

test('rejects unknown keys inside schema 1.1 blocks', function () {
    $integration = createSchemaElevenIntegration();
    $signed = makeSignedSchemaSnapshot($integration, [
        'payload' => [
            'whmcs' => [
                'cron' => [
                    'last_daily_run_at' => '2026-11-20T00:00:00Z',
                    'unexpected_field' => true,
                ],
            ],
        ],
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertUnprocessable();
});

test('rejects oversize id lists', function () {
    $integration = createSchemaElevenIntegration();
    $ids = range(1, 51);
    $signed = makeSignedSchemaSnapshot($integration, [
        'payload' => [
            'whmcs' => [
                'billing' => [
                    'overdue_without_reminder' => [
                        'count' => 51,
                        'invoice_ids' => $ids,
                    ],
                ],
            ],
        ],
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertUnprocessable();
});

test('rejects pii-like keys nested in the payload', function () {
    $integration = createSchemaElevenIntegration();
    $signed = makeSignedSchemaSnapshot($integration, [
        'payload' => [
            'whmcs' => [
                'mail' => [
                    'piping_failed_7d' => 1,
                    'customer_email' => 'client@example.test',
                ],
            ],
        ],
    ]);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertUnprocessable();
});

test('stores a schema 1.1 fixture end to end and fires b4 deterministic rules', function () {
    $integration = createSchemaElevenIntegration(['addon_version' => '1.0.0']);
    config(['monitoring.addon_latest_version' => '1.2.1']);
    $signed = makeSignedSchemaSnapshot($integration);

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $run = DiagnosticRun::query()->sole();
    $keys = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $run->id)
        ->where('source', 'rule')
        ->pluck('issue_key')
        ->all();

    expect($keys)->toContain('module.queue_failed')
        ->and($keys)->toContain('storage.disk_low')
        ->and($keys)->toContain('security.cve_2026_67398')
        ->and($keys)->toContain('tickets.sla_breach');
});

<?php

use App\Models\WhmcsIntegration;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ResellerBytesAiOps\Identifier;

require_once dirname(__DIR__, 3).'/whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/Text.php';
require_once dirname(__DIR__, 3).'/whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/Identifier.php';

function liveFaultsBasePayload(): array
{
    return json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/snapshots/schema_1_1_positive.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/**
 * @return array{body: string, headers: array<string, string>, secret: string}
 */
function makeSignedLiveFaultsSnapshot(WhmcsIntegration $integration, array $payload): array
{
    $snapshot = [
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.2',
        'collected_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'payload' => $payload,
    ];

    $body = json_encode($snapshot, JSON_THROW_ON_ERROR);
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
        'secret' => $integration->secret,
    ];
}

function createLiveFaultsIntegration(): WhmcsIntegration
{
    return WhmcsIntegration::create([
        'name' => 'Schema 1.1 live faults',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ]);
}

/**
 * Apply the same identifier / module-queue sanitization the addon uses before emit.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function sanitizeLiveFaultsLikeAddon(array $payload): array
{
    $items = $payload['whmcs']['module_queue']['items'] ?? [];
    $kept = [];
    $pendingCount = is_array($items) ? count($items) : 0;

    if (is_array($items)) {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $serviceId = (int) ($item['service_id'] ?? 0);
            if ($id < 1 || $serviceId < 1) {
                continue;
            }
            $kept[] = $item;
        }
    }

    $payload['whmcs']['module_queue']['items'] = $kept;
    $payload['whmcs']['module_queue']['pending_count'] = $pendingCount;

    $gateways = $payload['whmcs']['gateways'] ?? [];
    if (is_array($gateways)) {
        if (isset($gateways['active']) && is_array($gateways['active'])) {
            $gateways['active'] = Identifier::sanitizeNameList($gateways['active']);
        }
        foreach (['failures_7d', 'signature_failures_7d', 'unmatched_success_7d'] as $mapKey) {
            if (isset($gateways[$mapKey]) && is_array($gateways[$mapKey])) {
                $gateways[$mapKey] = Identifier::sanitizeCountMap($gateways[$mapKey]);
            }
        }
        $payload['whmcs']['gateways'] = $gateways;
    }

    return $payload;
}

test('rejects live-like schema 1.1 faults with field keys then accepts sanitized payload', function () {
    $integration = createLiveFaultsIntegration();
    $secret = $integration->secret;

    $faultyPayload = liveFaultsBasePayload();
    $faultyPayload['whmcs']['module_queue'] = [
        'source' => 'api',
        'pending_count' => 2,
        'oldest_age_minutes' => 10,
        'by_action' => [
            'create' => 1,
            'suspend' => 1,
            'unsuspend' => 0,
            'terminate' => 0,
            'other' => 0,
        ],
        'items' => [
            [
                'id' => 11,
                'service_type' => 'service',
                'service_id' => 0,
                'action' => 'create',
                'age_minutes' => 10,
                'retries' => 1,
                'error' => 'queued',
            ],
            [
                'id' => 12,
                'service_type' => 'service',
                'service_id' => 101,
                'action' => 'suspend',
                'age_minutes' => 5,
                'retries' => 0,
                'error' => 'ok',
            ],
        ],
    ];
    $faultyPayload['whmcs']['gateways'] = [
        'active' => ['PayPal (Recommended)', 'stripe'],
        'failures_7d' => [
            'PayPal (Recommended)' => 2,
        ],
        'signature_failures_7d' => [],
        'unmatched_success_7d' => [],
    ];

    $rejections = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$rejections): void {
        if ($event->level === 'warning' && $event->message === 'WHMCS integration request rejected.') {
            $rejections[] = $event->context;
        }
    });

    $faulty = makeSignedLiveFaultsSnapshot($integration, $faultyPayload);
    $response = $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $faulty['headers'],
        $faulty['body'],
    );

    $response->assertUnprocessable();
    $errors = $response->json('errors');
    expect($errors)->toBeArray()
        ->and($errors)->toHaveKey('payload.whmcs.module_queue.items.0.service_id');

    $gatewayFieldFailed = array_key_exists('payload.whmcs.gateways.failures_7d', $errors)
        || array_key_exists('payload.whmcs.gateways.active.0', $errors);
    expect($gatewayFieldFailed)->toBeTrue();

    expect($rejections)->not->toBeEmpty();
    $encoded = json_encode($rejections, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($secret)
        ->and($encoded)->not->toContain($faulty['headers']['HTTP_X_RB_SIGNATURE'])
        ->and($encoded)->not->toContain($faulty['headers']['HTTP_X_RB_NONCE']);

    $sanitizedPayload = sanitizeLiveFaultsLikeAddon($faultyPayload);
    $sanitized = makeSignedLiveFaultsSnapshot($integration, $sanitizedPayload);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $sanitized['headers'],
        $sanitized['body'],
    )->assertAccepted();

    expect($sanitizedPayload['whmcs']['module_queue']['pending_count'])->toBe(2)
        ->and($sanitizedPayload['whmcs']['module_queue']['items'])->toHaveCount(1)
        ->and($sanitizedPayload['whmcs']['gateways']['active'])->toContain('PayPal Recommended')
        ->and($sanitizedPayload['whmcs']['gateways']['failures_7d'])->toHaveKey('PayPal Recommended');
});

test('still accepts a clean schema 1.1 snapshot after live-fault hardening', function () {
    $integration = createLiveFaultsIntegration();
    $signed = makeSignedLiveFaultsSnapshot($integration, liveFaultsBasePayload());

    $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();
});

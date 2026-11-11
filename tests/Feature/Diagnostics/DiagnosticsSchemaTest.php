<?php

use App\Models\DiagnosticRun;
use App\Models\IntegrationRequestNonce;
use App\Models\WhmcsIntegration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

function createWhmcsIntegration(array $overrides = []): WhmcsIntegration
{
    return WhmcsIntegration::create(array_merge([
        'name' => 'Production WHMCS',
        'status' => 'active',
        'secret' => 'test-secret-that-must-never-be-serialized',
        'secret_version' => 1,
    ], $overrides));
}

test('integration secrets are encrypted at rest and hidden from serialization', function () {
    $secret = 'test-secret-that-must-never-be-serialized';
    $integration = createWhmcsIntegration(['secret' => $secret]);

    $storedSecret = DB::table('whmcs_integrations')
        ->where('id', $integration->id)
        ->value('secret');

    expect($storedSecret)
        ->not->toBe($secret)
        ->and($integration->fresh()->secret)->toBe($secret)
        ->and($integration->toArray())->not->toHaveKey('secret');
});

test('diagnostic snapshots belong to an integration and retain structured payloads', function () {
    $integration = createWhmcsIntegration();

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.0',
        'addon_version' => '1.0.0',
        'status' => 'received',
        'collected_at' => \Illuminate\Support\Carbon::now(),
        'received_at' => Carbon::now(),
        'payload' => [
            'whmcs' => ['version' => '8.13.7'],
            'php' => ['web_version' => '8.1.34'],
        ],
        'payload_sha256' => str_repeat('a', 64),
    ]);

    expect($run->fresh()->integration->is($integration))->toBeTrue()
        ->and($integration->fresh()->diagnosticRuns)->toHaveCount(1)
        ->and($run->fresh()->payload['whmcs']['version'])->toBe('8.13.7');
});

test('an integration nonce cannot be inserted twice', function () {
    $integration = createWhmcsIntegration();
    $attributes = [
        'integration_id' => $integration->id,
        'nonce' => 'one-time-request-nonce',
        'expires_at' => Carbon::now()->addMinutes(5),
        'created_at' => Carbon::now(),
    ];

    IntegrationRequestNonce::create($attributes);

    expect(fn () => IntegrationRequestNonce::create($attributes))
        ->toThrow(QueryException::class);
});

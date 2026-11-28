<?php

use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Providers\RouteRateLimiterServiceProvider;
use App\Services\Tenancy\TenantResourceLimitService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @return list<array<string, mixed>>
 */
function captureWhmcsRejectionLogs(callable $callback): array
{
    $rejections = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$rejections): void {
        if ($event->level === 'warning' && $event->message === 'WHMCS integration request rejected.') {
            $rejections[] = $event->context;
        }
    });

    $callback();

    return $rejections;
}

/**
 * @param  array<string, mixed>  $context
 */
function assertWhmcsRejectionContextIsSafe(array $context, string $secret, string $signature, string $nonce): void
{
    $encoded = json_encode($context, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain($secret)
        ->and($encoded)->not->toContain($signature)
        ->and($encoded)->not->toContain($nonce)
        ->and($context)->not->toHaveKey('secret')
        ->and($context)->not->toHaveKey('signature')
        ->and($context)->not->toHaveKey('nonce')
        ->and($context)->not->toHaveKey('body')
        ->and($context)->not->toHaveKey('payload');
}

/**
 * @return array{body: string, headers: array<string, string>, secret: string, signature: string, nonce: string, integration: WhmcsIntegration}
 */
function makeLoggedWhmcsSnapshotRequest(WhmcsIntegration $integration, array $overrides = [], ?string $nonce = null, ?int $timestamp = null, ?string $rawBody = null): array
{
    $payload = array_replace_recursive([
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
                'extensions' => ['curl' => true],
            ],
            'collector' => [
                'capabilities' => ['php_extension_availability'],
                'limitations' => ['module_status_not_collected'],
            ],
        ],
    ], $overrides);

    $body = $rawBody ?? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
    $signature = hash_hmac('sha256', $canonical, $integration->secret);

    return [
        'body' => $body,
        'headers' => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => $signature,
        ],
        'secret' => $integration->secret,
        'signature' => $signature,
        'nonce' => $nonce,
        'integration' => $integration,
    ];
}

function createRejectionLoggingIntegration(array $overrides = []): WhmcsIntegration
{
    return WhmcsIntegration::query()->create(array_merge([
        'name' => 'Rejection logging integration',
        'status' => 'active',
        'secret' => bin2hex(random_bytes(32)),
        'secret_version' => 1,
    ], $overrides));
}

/**
 * @return array{owner: User, tenant: Tenant, integration: WhmcsIntegration, subscription: TenantSubscription}
 */
function createRejectionLoggingWorkspace(array $subscriptionOverrides = [], array $tenantOverrides = []): array
{
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create(array_merge([
        'name' => 'Rejection Workspace',
        'slug' => 'rejection-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ], $tenantOverrides));
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);
    $subscription = TenantSubscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'plan_code' => 'growth',
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 14900,
        'entitlements' => [
            'limits' => [
                'integrations' => 5,
                'members' => 10,
                'diagnostics_per_period' => 100,
                'retention_days' => 30,
            ],
        ],
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ], $subscriptionOverrides));
    $integration = createRejectionLoggingIntegration([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
    ]);

    return compact('owner', 'tenant', 'integration', 'subscription');
}

test('logs query_string_present for signed requests with a query string', function () {
    $integration = createRejectionLoggingIntegration();
    $signed = makeLoggedWhmcsSnapshotRequest($integration);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots?x=1',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertStatus(400);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('query_string_present')
        ->and($rejections[0]['status'])->toBe(400)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs body_invalid for non-json WHMCS bodies', function () {
    $integration = createRejectionLoggingIntegration();
    $nonce = Str::random(64);
    $timestamp = (string) now()->timestamp;
    $body = 'not-json';
    $canonical = implode("\n", ['POST', '/api/v1/whmcs/diagnostic-snapshots', $timestamp, $nonce, hash('sha256', $body)]);
    $signature = hash_hmac('sha256', $canonical, $integration->secret);

    $rejections = captureWhmcsRejectionLogs(function () use ($integration, $timestamp, $nonce, $signature, $body): void {
        $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => $signature,
        ], $body)->assertStatus(400);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('body_invalid')
        ->and($rejections[0]['status'])->toBe(400)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $integration->secret, $signature, $nonce);
});

test('logs headers_invalid for malformed authentication headers', function () {
    $integration = createRejectionLoggingIntegration();
    $signed = makeLoggedWhmcsSnapshotRequest($integration);
    $signed['headers']['HTTP_X_RB_SIGNATURE'] = 'not-a-signature';

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertUnauthorized();
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('headers_invalid')
        ->and($rejections[0]['status'])->toBe(401)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs clock_skew with skew_seconds for stale timestamps', function () {
    $integration = createRejectionLoggingIntegration();
    $signed = makeLoggedWhmcsSnapshotRequest($integration, timestamp: now()->subMinutes(20)->timestamp);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertUnauthorized();
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('clock_skew')
        ->and($rejections[0]['status'])->toBe(401)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8))
        ->and($rejections[0]['skew_seconds'])->toBeInt()
        ->and($rejections[0]['skew_seconds'])->toBeGreaterThan(300);
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs integration_not_found_or_inactive for unknown integrations', function () {
    $integration = createRejectionLoggingIntegration(['status' => 'revoked']);
    $signed = makeLoggedWhmcsSnapshotRequest($integration);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertUnauthorized();
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('integration_not_found_or_inactive')
        ->and($rejections[0]['status'])->toBe(401)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs signature_mismatch for invalid HMAC signatures', function () {
    $integration = createRejectionLoggingIntegration();
    $signed = makeLoggedWhmcsSnapshotRequest($integration);
    $badSignature = str_repeat('0', 64);
    $signed['headers']['HTTP_X_RB_SIGNATURE'] = $badSignature;

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertUnauthorized();
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('signature_mismatch')
        ->and($rejections[0]['status'])->toBe(401)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $badSignature, $signed['nonce']);
});

test('logs nonce_replay for reused nonces', function () {
    config(['services.openai.key' => null]);
    $integration = createRejectionLoggingIntegration();
    $signed = makeLoggedWhmcsSnapshotRequest($integration);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $signed['headers'],
        $signed['body'],
    )->assertAccepted();

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertStatus(409);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('nonce_replay')
        ->and($rejections[0]['status'])->toBe(409)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs tenant_inactive when the workspace is not active', function () {
    $workspace = createRejectionLoggingWorkspace(tenantOverrides: [
        'status' => Tenant::STATUS_SUSPENDED,
    ]);
    $signed = makeLoggedWhmcsSnapshotRequest($workspace['integration']);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertForbidden();
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('tenant_inactive')
        ->and($rejections[0]['status'])->toBe(403)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($workspace['integration']->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs subscription_or_quota_denied when diagnostic allowance is exhausted', function () {
    $workspace = createRejectionLoggingWorkspace([
        'entitlements' => [
            'limits' => [
                'integrations' => 5,
                'members' => 10,
                'diagnostics_per_period' => 0,
                'retention_days' => 30,
            ],
        ],
    ]);
    $signed = makeLoggedWhmcsSnapshotRequest($workspace['integration']);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertForbidden();
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('subscription_or_quota_denied')
        ->and($rejections[0]['status'])->toBe(403)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($workspace['integration']->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs not_found for missing safe action results', function () {
    $integration = createRejectionLoggingIntegration();
    $executionId = (string) Str::uuid();
    $body = json_encode([
        'status' => 'success',
        'summary' => 'done',
        'evidence' => [],
    ], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $nonce = Str::random(64);
    $path = '/api/v1/whmcs/safe-actions/'.$executionId.'/result';
    $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', $body)]);
    $signature = hash_hmac('sha256', $canonical, $integration->secret);

    $rejections = captureWhmcsRejectionLogs(function () use ($integration, $path, $timestamp, $nonce, $signature, $body): void {
        $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => $signature,
        ], $body)->assertNotFound();
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('not_found')
        ->and($rejections[0]['status'])->toBe(404)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $integration->secret, $signature, $nonce);
});

test('logs snapshot_conflict when the same snapshot uuid arrives with a different payload', function () {
    config(['services.openai.key' => null]);
    $integration = createRejectionLoggingIntegration();
    $snapshotUuid = (string) Str::uuid();
    $first = makeLoggedWhmcsSnapshotRequest($integration, [
        'snapshot_uuid' => $snapshotUuid,
        'payload' => [
            'system' => ['whmcs_version' => '8.13.7'],
        ],
    ]);

    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $first['headers'],
        $first['body'],
    )->assertAccepted();

    expect(DiagnosticRun::query()->where('snapshot_uuid', $snapshotUuid)->count())->toBe(1);

    $second = makeLoggedWhmcsSnapshotRequest($integration, [
        'snapshot_uuid' => $snapshotUuid,
        'payload' => [
            'system' => ['whmcs_version' => '8.13.8'],
        ],
    ]);

    $rejections = captureWhmcsRejectionLogs(function () use ($second): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $second['headers'],
            $second['body'],
        )->assertStatus(409);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('snapshot_conflict')
        ->and($rejections[0]['status'])->toBe(409)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $second['secret'], $second['signature'], $second['nonce']);
});

test('logs validation_failed with field names only for invalid snapshot payloads', function () {
    $integration = createRejectionLoggingIntegration();
    $body = json_encode(['unexpected' => true], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $nonce = Str::random(64);
    $path = '/api/v1/whmcs/diagnostic-snapshots';
    $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', $body)]);
    $signature = hash_hmac('sha256', $canonical, $integration->secret);

    $rejections = captureWhmcsRejectionLogs(function () use ($integration, $timestamp, $nonce, $signature, $body): void {
        $this->call('POST', '/api/v1/whmcs/diagnostic-snapshots', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RB_INTEGRATION' => $integration->id,
            'HTTP_X_RB_TIMESTAMP' => $timestamp,
            'HTTP_X_RB_NONCE' => $nonce,
            'HTTP_X_RB_SIGNATURE' => $signature,
        ], $body)->assertStatus(422);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('validation_failed')
        ->and($rejections[0]['status'])->toBe(422)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8))
        ->and($rejections[0]['validation_fields'])->toBeArray()
        ->and($rejections[0]['validation_fields'])->not->toBeEmpty();

    $encoded = json_encode($rejections[0], JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($integration->secret)
        ->and($encoded)->not->toContain($signature)
        ->and($encoded)->not->toContain($nonce)
        ->and($encoded)->not->toContain('unexpected');
});

test('logs rate_limited when the WHMCS diagnostic throttle is exceeded', function () {
    config(['services.openai.key' => null]);
    RateLimiter::for('api-whmcs-diagnostic-snapshots', fn () => Limit::perMinute(1));

    $integration = createRejectionLoggingIntegration();
    $first = makeLoggedWhmcsSnapshotRequest($integration);
    $this->call(
        'POST',
        '/api/v1/whmcs/diagnostic-snapshots',
        [],
        [],
        [],
        $first['headers'],
        $first['body'],
    )->assertAccepted();

    $second = makeLoggedWhmcsSnapshotRequest($integration);

    $rejections = captureWhmcsRejectionLogs(function () use ($second): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $second['headers'],
            $second['body'],
        )->assertStatus(429);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('rate_limited')
        ->and($rejections[0]['status'])->toBe(429)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($integration->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $second['secret'], $second['signature'], $second['nonce']);

    RateLimiter::for(
        'api-whmcs-diagnostic-snapshots',
        fn () => Limit::perMinute(RouteRateLimiterServiceProvider::LIMITS['api-whmcs-diagnostic-snapshots']),
    );
});

test('logs server_error when a downstream exception escapes the route action', function () {
    $workspace = createRejectionLoggingWorkspace();
    $this->mock(TenantResourceLimitService::class, function ($mock): void {
        $mock->shouldReceive('requireDiagnosticCapacity')
            ->once()
            ->andThrow(new RuntimeException('simulated downstream failure'));
    });
    $signed = makeLoggedWhmcsSnapshotRequest($workspace['integration']);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertStatus(500);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('server_error')
        ->and($rejections[0]['status'])->toBe(500)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($workspace['integration']->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('logs rejected for unexpected downstream HTTP statuses', function () {
    $workspace = createRejectionLoggingWorkspace();
    $this->mock(TenantResourceLimitService::class, function ($mock): void {
        $mock->shouldReceive('requireDiagnosticCapacity')
            ->once()
            ->andThrow(new HttpException(418, 'teapot'));
    });
    $signed = makeLoggedWhmcsSnapshotRequest($workspace['integration']);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertStatus(418);
    });

    expect($rejections)->toHaveCount(1)
        ->and($rejections[0]['reason'])->toBe('rejected')
        ->and($rejections[0]['status'])->toBe(418)
        ->and($rejections[0]['integration_prefix'])->toBe(substr($workspace['integration']->id, 0, 8));
    assertWhmcsRejectionContextIsSafe($rejections[0], $signed['secret'], $signed['signature'], $signed['nonce']);
});

test('does not log a rejection for a valid signed diagnostic snapshot', function () {
    config(['services.openai.key' => null]);
    $integration = createRejectionLoggingIntegration();
    $signed = makeLoggedWhmcsSnapshotRequest($integration);

    $rejections = captureWhmcsRejectionLogs(function () use ($signed): void {
        $this->call(
            'POST',
            '/api/v1/whmcs/diagnostic-snapshots',
            [],
            [],
            [],
            $signed['headers'],
            $signed['body'],
        )->assertAccepted();
    });

    expect($rejections)->toBeEmpty();
});

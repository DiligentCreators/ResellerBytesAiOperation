<?php

use App\Events\InstallationHeartbeatStateChanged;
use App\Models\AuditEvent;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantNotification;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FindingContinuityService;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\Monitoring\AutoCollectDueEvaluator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;

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
        'auto_collect_enabled' => true,
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_UNKNOWN,
    ], $overrides));
}

function createHeartbeatTenantWorkspace(User $owner): array
{
    $tenant = Tenant::create([
        'name' => 'Heartbeat Workspace',
        'slug' => 'heartbeat-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    $integration = createHeartbeatIntegration([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Primary WHMCS',
    ]);

    return compact('tenant', 'integration');
}

test('accepts a signed system heartbeat and stores heartbeat metadata', function () {
    $integration = createHeartbeatIntegration();
    $signed = makeSignedHeartbeat($integration, [
        'cli_php_version' => '8.2.12',
        'cron_kind' => 'system',
    ]);

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted()
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.auto_collect_due', true)
        ->assertJsonPath('data.pending_approved_actions', false);

    $fresh = $integration->fresh();
    expect($fresh->last_heartbeat_at)->not->toBeNull()
        ->and($fresh->last_cron_at)->not->toBeNull()
        ->and($fresh->cron_php_version)->toBe('8.2.12')
        ->and($fresh->last_daily_cron_at)->toBeNull()
        ->and($fresh->addon_version)->toBe('1.2.0')
        ->and($fresh->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_OK)
        ->and(AuditEvent::where('event_type', 'whmcs_heartbeat.accepted')->count())->toBe(1);
});

test('daily heartbeat also updates last_daily_cron_at', function () {
    $integration = createHeartbeatIntegration();
    $signed = makeSignedHeartbeat($integration, ['cron_kind' => 'daily']);

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    $fresh = $integration->fresh();
    expect($fresh->last_cron_at)->not->toBeNull()
        ->and($fresh->last_daily_cron_at)->not->toBeNull()
        ->and($fresh->last_heartbeat_at?->equalTo($fresh->last_daily_cron_at))->toBeTrue();
});

test('rejects heartbeat payloads with unsupported fields', function () {
    $integration = createHeartbeatIntegration();
    $signed = makeSignedHeartbeat($integration, ['customer_email' => 'nope@example.test']);

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertUnprocessable();
});

test('heartbeat signature and nonce are required', function () {
    $integration = createHeartbeatIntegration();
    $signed = makeSignedHeartbeat($integration);

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $signed['body'])->assertUnauthorized();

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_RB_INTEGRATION' => $integration->id,
        'HTTP_X_RB_TIMESTAMP' => (string) now()->timestamp,
        'HTTP_X_RB_NONCE' => Str::random(64),
        'HTTP_X_RB_SIGNATURE' => str_repeat('a', 64),
    ], $signed['body'])->assertUnauthorized();

    $first = makeSignedHeartbeat($integration);
    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $first['headers'], $first['body'])
        ->assertAccepted();

    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $first['headers'], $first['body'])
        ->assertStatus(409);
});

test('heartbeat reports pending approved actions', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $integration = createHeartbeatIntegration(['owner_user_id' => $owner->id]);
    SafeActionExecution::create([
        'integration_id' => $integration->id,
        'tenant_id' => null,
        'finding_id' => null,
        'action_key' => 'request_diagnostic_collect',
        'status' => SafeActionExecution::STATUS_AWAITING_AGENT,
        'risk_level' => 'low',
        'requested_by_user_id' => $owner->id,
        'approved_by_user_id' => $owner->id,
        'approved_at' => now(),
        'requested_at' => now(),
        'input' => [],
    ]);

    $signed = makeSignedHeartbeat($integration);
    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted()
        ->assertJsonPath('data.pending_approved_actions', true);
});

test('auto-collect due logic honours interval and daily cap', function () {
    $integration = createHeartbeatIntegration([
        'last_auto_collect_at' => now('UTC')->subMinutes(10),
    ]);

    config([
        'monitoring.auto_collect_interval_minutes' => 60,
        'monitoring.max_snapshots_per_install_per_day' => 2,
    ]);

    expect(app(AutoCollectDueEvaluator::class)->isDue($integration))->toBeFalse();

    $integration->forceFill(['last_auto_collect_at' => now('UTC')->subMinutes(90)])->save();
    expect(app(AutoCollectDueEvaluator::class)->isDue($integration->fresh()))->toBeTrue();

    DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['collector' => ['capabilities' => [], 'limitations' => []]],
        'payload_sha256' => hash('sha256', 'a'),
    ]);
    DiagnosticRun::create([
        'integration_id' => $integration->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.0',
        'status' => 'received',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['collector' => ['capabilities' => [], 'limitations' => []]],
        'payload_sha256' => hash('sha256', 'b'),
    ]);

    expect(app(AutoCollectDueEvaluator::class)->isDue($integration->fresh()))->toBeFalse();

    $integration->forceFill(['auto_collect_enabled' => false, 'last_auto_collect_at' => null])->save();
    DiagnosticRun::query()->where('integration_id', $integration->id)->delete();
    expect(app(AutoCollectDueEvaluator::class)->isDue($integration->fresh()))->toBeFalse();
});

test('heartbeat state machine transitions ok to stale to down to ok with notifications once per change', function () {
    Event::fake([InstallationHeartbeatStateChanged::class]);

    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    ['tenant' => $tenant, 'integration' => $integration] = createHeartbeatTenantWorkspace($owner);

    config(['monitoring.heartbeat_stale_minutes' => 30]);

    $signed = makeSignedHeartbeat($integration);
    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $signed['headers'], $signed['body'])
        ->assertAccepted();

    Event::assertDispatched(InstallationHeartbeatStateChanged::class, function (InstallationHeartbeatStateChanged $event) use ($integration): bool {
        return $event->integrationId === $integration->id
            && $event->previousState === WhmcsIntegration::HEARTBEAT_STATE_UNKNOWN
            && $event->newState === WhmcsIntegration::HEARTBEAT_STATE_OK;
    });

    expect(TenantNotification::query()->where('event_type', 'installation.heartbeat.state_changed')->count())->toBe(1);

    $integration->forceFill([
        'last_heartbeat_at' => now('UTC')->subMinutes(45),
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
    ])->save();

    $this->artisan('whmcs:heartbeats:check')->assertSuccessful();
    expect($integration->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_STALE)
        ->and(DiagnosticFinding::query()->where('issue_key', 'heartbeat.missed')->active()->count())->toBe(1)
        ->and(TenantNotification::query()->where('event_type', 'installation.heartbeat.state_changed')->count())->toBe(2);

    $this->artisan('whmcs:heartbeats:check')->assertSuccessful();
    expect(TenantNotification::query()->where('event_type', 'installation.heartbeat.state_changed')->count())->toBe(2);

    $integration->forceFill([
        'last_heartbeat_at' => now('UTC')->subMinutes(100),
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_STALE,
    ])->save();

    $this->artisan('whmcs:heartbeats:check')->assertSuccessful();
    expect($integration->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_DOWN)
        ->and(TenantNotification::query()->where('event_type', 'installation.heartbeat.state_changed')->count())->toBe(3);

    $resume = makeSignedHeartbeat($integration);
    $this->call('POST', '/api/v1/whmcs/heartbeats', [], [], [], $resume['headers'], $resume['body'])
        ->assertAccepted();

    expect($integration->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_OK)
        ->and(DiagnosticFinding::query()->where('issue_key', 'heartbeat.missed')->active()->count())->toBe(0)
        ->and(TenantNotification::query()->where('event_type', 'installation.heartbeat.state_changed')->count())->toBe(4);

    Event::assertDispatched(InstallationHeartbeatStateChanged::class, 4);
});

test('heartbeats check command is safe with zero integrations', function () {
    $this->artisan('whmcs:heartbeats:check')
        ->expectsOutputToContain('Checked 0 integration(s); updated 0 heartbeat state(s); 0 failed.')
        ->assertSuccessful();
});

test('heartbeats check reopens a resolved heartbeat.missed finding on the latest run', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    ['integration' => $integration] = createHeartbeatTenantWorkspace($owner);

    config(['monitoring.heartbeat_stale_minutes' => 30]);

    $catalog = WhmcsIssueCatalog::get('heartbeat.missed');
    expect($catalog)->not->toBeNull();

    $findingData = [
        'category' => (string) ($catalog['category'] ?? 'cron'),
        'title' => (string) ($catalog['title'] ?? 'Install heartbeat is overdue'),
    ];
    $fingerprint = app(FindingContinuityService::class)
        ->fingerprintForIssueKey('heartbeat.missed', $findingData);

    $run = DiagnosticRun::create([
        'integration_id' => $integration->id,
        'tenant_id' => $integration->tenant_id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.2.0',
        'status' => 'analyzed',
        'collected_at' => now('UTC'),
        'received_at' => now('UTC'),
        'payload' => ['collector' => ['capabilities' => [], 'limitations' => []]],
        'payload_sha256' => hash('sha256', 'heartbeat-reopen-'.$integration->id),
    ]);

    DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $integration->tenant_id,
        'fingerprint' => $fingerprint,
        'issue_key' => 'heartbeat.missed',
        'source' => DiagnosticFinding::SOURCE_RULE,
        'affected_count' => 1,
        'fix_mode' => 'guided',
        'category' => $findingData['category'],
        'severity' => 'critical',
        'title' => $findingData['title'],
        'summary' => 'Previously resolved missed heartbeat.',
        'evidence' => ['integration.heartbeat_state' => 'stale'],
        'confidence' => 1,
        'status' => DiagnosticFinding::STATUS_RESOLVED,
        'status_note' => 'Heartbeats resumed.',
        'status_updated_at' => now('UTC')->subHour(),
        'resolved_at' => now('UTC')->subHour(),
    ]);

    $integration->forceFill([
        'last_heartbeat_at' => now('UTC')->subMinutes(45),
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
    ])->save();

    $this->artisan('whmcs:heartbeats:check')->assertSuccessful();

    $findings = DiagnosticFinding::query()
        ->where('diagnostic_run_id', $run->id)
        ->where('fingerprint', $fingerprint)
        ->get();

    expect($findings)->toHaveCount(1)
        ->and($integration->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_STALE)
        ->and($findings->first()->status)->toBe(DiagnosticFinding::STATUS_OPEN)
        ->and($findings->first()->resolved_at)->toBeNull()
        ->and($findings->first()->status_note)->toBeNull();
});

test('heartbeats check continues after one installation fails and exits non-zero', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    ['integration' => $failing] = createHeartbeatTenantWorkspace($owner);
    ['integration' => $healthy] = createHeartbeatTenantWorkspace($owner);

    config(['monitoring.heartbeat_stale_minutes' => 30]);

    foreach ([$failing, $healthy] as $integration) {
        $integration->forceFill([
            'last_heartbeat_at' => now('UTC')->subMinutes(45),
            'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
        ])->save();
    }

    Event::listen(InstallationHeartbeatStateChanged::class, function (InstallationHeartbeatStateChanged $event) use ($failing): void {
        if ($event->integrationId === $failing->id) {
            throw new RuntimeException('simulated heartbeat check failure');
        }
    });

    Log::spy();

    $this->artisan('whmcs:heartbeats:check')
        ->expectsOutputToContain('1 failed')
        ->assertFailed();

    expect($failing->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_OK)
        ->and($healthy->fresh()->heartbeat_state)->toBe(WhmcsIntegration::HEARTBEAT_STATE_STALE);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($failing): bool {
            return $message === 'whmcs.heartbeats.check_failed'
                && ($context['integration_id'] ?? null) === $failing->id
                && ($context['exception'] ?? null) === RuntimeException::class
                && str_contains((string) ($context['message'] ?? ''), 'simulated heartbeat check failure');
        })
        ->once();
});

test('integrations index exposes heartbeat monitoring props and auto-collect toggle', function () {
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    ['tenant' => $tenant, 'integration' => $integration] = createHeartbeatTenantWorkspace($owner);

    $integration->forceFill([
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
        'last_heartbeat_at' => now(),
        'cron_php_version' => '8.2.12',
        'auto_collect_enabled' => true,
    ])->save();

    $this->actingAs($owner)
        ->get(route('integrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/index')
            ->has('integrations.data', 1)
            ->where('integrations.data.0.heartbeat_state', 'ok')
            ->where('integrations.data.0.cron_php_version', '8.2.12')
            ->where('integrations.data.0.auto_collect_enabled', true)
            ->where('integrations.data.0.last_heartbeat_at', fn ($value) => is_string($value) && $value !== ''));

    $this->actingAs($owner)
        ->put(route('integrations.auto-collect.update', $integration), [
            'auto_collect_enabled' => false,
        ])
        ->assertRedirect();

    expect($integration->fresh()->auto_collect_enabled)->toBeFalse();
});

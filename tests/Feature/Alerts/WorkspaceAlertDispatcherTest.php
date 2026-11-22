<?php

use App\Events\CriticalFindingRaised;
use App\Events\FixActionFailed;
use App\Events\InstallationHeartbeatStateChanged;
use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantAlertChannel;
use App\Models\TenantAlertDelivery;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use App\Services\Mail\CentralOutboundMailer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function createAlertWorkspace(array $entitlementOverrides = []): array
{
    $owner = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Alert Workspace',
        'slug' => 'alert-ws-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $owner->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $owner->id,
        'role' => TenantMembership::ROLE_OWNER,
        'status' => TenantMembership::STATUS_ACTIVE,
        'accepted_at' => now(),
    ]);

    TenantSubscription::create([
        'tenant_id' => $tenant->id,
        'status' => 'active',
        'plan_code' => 'growth',
        'billing_mode' => 'manual',
        'currency' => 'USD',
        'unit_amount_minor' => 14900,
        'entitlements' => array_merge([
            'alerts.channels' => true,
            'diagnostics.analysis' => true,
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ], $entitlementOverrides),
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    $integration = WhmcsIntegration::create([
        'owner_user_id' => $owner->id,
        'tenant_id' => $tenant->id,
        'name' => 'Primary WHMCS',
        'status' => 'active',
        'secret' => Str::random(64),
        'secret_version' => 1,
        'addon_version' => '1.1.0',
        'heartbeat_state' => WhmcsIntegration::HEARTBEAT_STATE_OK,
        'last_heartbeat_at' => now()->subHour(),
    ]);

    return compact('owner', 'tenant', 'integration');
}

function createAlertChannel(Tenant $tenant, string $type, string $target, array $overrides = []): TenantAlertChannel
{
    return TenantAlertChannel::query()->create(array_merge([
        'tenant_id' => $tenant->id,
        'type' => $type,
        'name' => ucfirst($type).' channel',
        'target' => $target,
        'signing_secret' => $type === TenantAlertChannel::TYPE_WEBHOOK ? Str::random(64) : null,
        'events' => ['all_critical'],
        'enabled' => true,
    ], $overrides));
}

beforeEach(function () {
    config(['alerts.repeat_minutes' => 60]);
});

it('sends one heartbeat-down alert per subscribed channel', function () {
    $workspace = createAlertWorkspace();
    $slackUrl = 'https://hooks.example.test/slack/'.Str::random(8);
    $discordUrl = 'https://hooks.example.test/discord/'.Str::random(8);

    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_SLACK, $slackUrl);
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_DISCORD, $discordUrl);

    Http::fake([
        'hooks.example.test/*' => Http::response('', 200),
    ]);

    event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        CarbonImmutable::now('UTC'),
    ));

    expect(TenantAlertDelivery::query()->where('status', TenantAlertDelivery::STATUS_SENT)->count())->toBe(2);

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === $slackUrl
        && str_contains((string) $request['text'], 'heartbeat is down'));
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === $discordUrl
        && str_contains((string) $request['content'], 'heartbeat is down'));
});

it('dedupes the same alert within the repeat window and sends recovery', function () {
    $workspace = createAlertWorkspace();
    $slackUrl = 'https://hooks.example.test/slack/'.Str::random(8);
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_SLACK, $slackUrl);

    Http::fake(['hooks.example.test/*' => Http::response('', 200)]);

    $changedAt = CarbonImmutable::parse('2026-11-22T10:00:00Z');

    event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        $changedAt,
    ));

    event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        $changedAt->addMinutes(5),
    ));

    expect(TenantAlertDelivery::query()->where('status', TenantAlertDelivery::STATUS_SENT)->count())->toBe(1)
        ->and(TenantAlertDelivery::query()->where('status', TenantAlertDelivery::STATUS_SUPPRESSED)->count())->toBe(1);

    event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        $changedAt->addMinutes(10),
    ));

    expect(TenantAlertDelivery::query()->where('alert_key', 'installation.heartbeat.recovery')->where('status', 'sent')->count())->toBe(1);

    Http::assertSent(fn (HttpRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'back online'));
});

it('alerts on new critical findings', function () {
    $workspace = createAlertWorkspace();
    $slackUrl = 'https://hooks.example.test/slack/'.Str::random(8);
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_SLACK, $slackUrl, [
        'events' => ['finding.critical'],
    ]);

    Http::fake(['hooks.example.test/*' => Http::response('', 200)]);

    $run = DiagnosticRun::create([
        'integration_id' => $workspace['integration']->id,
        'tenant_id' => $workspace['tenant']->id,
        'snapshot_uuid' => (string) Str::uuid(),
        'schema_version' => '1.1',
        'addon_version' => '1.1.0',
        'status' => 'analyzed',
        'collected_at' => now(),
        'received_at' => now(),
        'payload' => ['system' => []],
        'payload_sha256' => hash('sha256', Str::random(16)),
    ]);

    $finding = DiagnosticFinding::create([
        'diagnostic_run_id' => $run->id,
        'tenant_id' => $workspace['tenant']->id,
        'fingerprint' => hash('sha256', 'critical-finding'),
        'issue_key' => 'cron.not_running',
        'source' => 'rule',
        'category' => 'cron',
        'severity' => 'critical',
        'title' => 'System cron is not running',
        'summary' => 'WHMCS system cron appears overdue.',
        'evidence' => [],
        'status' => DiagnosticFinding::STATUS_OPEN,
    ]);

    event(new CriticalFindingRaised($finding->id, $workspace['tenant']->id));

    expect(TenantAlertDelivery::query()->where('alert_key', 'finding.critical')->where('status', 'sent')->count())->toBe(1);
    Http::assertSent(fn (HttpRequest $request): bool => str_contains((string) $request['text'], 'Critical finding'));
});

it('posts slack discord and signed webhook payloads', function () {
    $workspace = createAlertWorkspace();
    $slackUrl = 'https://hooks.example.test/slack/'.Str::random(8);
    $discordUrl = 'https://hooks.example.test/discord/'.Str::random(8);
    $webhookUrl = 'https://hooks.example.test/webhook/'.Str::random(8);
    $signingSecret = Str::random(64);

    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_SLACK, $slackUrl);
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_DISCORD, $discordUrl);
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_WEBHOOK, $webhookUrl, [
        'signing_secret' => $signingSecret,
    ]);

    Http::fake(['hooks.example.test/*' => Http::response('', 204)]);

    app(AlertDispatcher::class)->dispatch(
        $workspace['tenant']->id,
        'installation.heartbeat.down',
        'payload-check:'.$workspace['integration']->id,
        new AlertMessage(
            title: 'Installation heartbeat is down',
            body: 'No heartbeat was received.',
            installationName: 'Primary WHMCS',
            fixCenterUrl: 'https://app.example.test/fix-center',
        ),
    );

    Http::assertSentCount(3);

    Http::assertSent(function (HttpRequest $request) use ($webhookUrl, $signingSecret): bool {
        if ($request->url() !== $webhookUrl) {
            return false;
        }

        $header = (string) config('alerts.hmac_header');
        $signature = $request->header($header)[0] ?? '';
        $body = $request->body();
        $expected = 'sha256='.hash_hmac('sha256', $body, $signingSecret);

        return $signature === $expected
            && str_contains($body, 'installation.heartbeat.down')
            && str_contains($body, 'Primary WHMCS');
    });
});

it('skips external channels when alerts.channels entitlement is missing', function () {
    $workspace = createAlertWorkspace(['alerts.channels' => false]);
    $slackUrl = 'https://hooks.example.test/slack/'.Str::random(8);
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_SLACK, $slackUrl);

    Http::fake(['hooks.example.test/*' => Http::response('', 200)]);

    event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        CarbonImmutable::now('UTC'),
    ));

    expect(TenantAlertDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('records quiet failures without throwing into the caller', function () {
    $workspace = createAlertWorkspace();
    $slackUrl = 'https://hooks.example.test/slack/'.Str::random(8);
    $channel = createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_SLACK, $slackUrl);

    Http::fake(['hooks.example.test/*' => Http::response('nope', 503)]);

    expect(fn () => event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        CarbonImmutable::now('UTC'),
    )))->not->toThrow(Throwable::class);

    expect($channel->fresh()->last_error)->not->toBeNull()
        ->and(TenantAlertDelivery::query()->where('status', TenantAlertDelivery::STATUS_FAILED)->count())->toBe(1);
});

it('dispatches fix-failed alerts for failed safe actions', function () {
    $workspace = createAlertWorkspace();
    $slackUrl = 'https://hooks.example.test/slack/'.Str::random(8);
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_SLACK, $slackUrl, [
        'events' => ['fix.failed'],
    ]);

    Http::fake(['hooks.example.test/*' => Http::response('', 200)]);

    $execution = SafeActionExecution::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'integration_id' => $workspace['integration']->id,
        'action_key' => 'whmcs_clear_template_cache',
        'mode' => SafeActionExecution::MODE_ONE_CLICK,
        'status' => SafeActionExecution::STATUS_FAILED,
        'risk_level' => 'low',
        'requested_by_user_id' => $workspace['owner']->id,
        'requested_at' => now(),
    ]);

    event(new FixActionFailed($execution->id, $workspace['tenant']->id));

    expect(TenantAlertDelivery::query()->where('alert_key', 'fix.failed')->where('status', 'sent')->count())->toBe(1);
});

it('sends email channel alerts through the central outbound mailer', function () {
    $workspace = createAlertWorkspace();
    $email = 'ops-'.Str::random(8).'@example.test';
    createAlertChannel($workspace['tenant'], TenantAlertChannel::TYPE_EMAIL, $email);

    $mailer = Mockery::mock(CentralOutboundMailer::class);
    $mailer->shouldReceive('send')
        ->once()
        ->withArgs(fn (string $to, string $subject): bool => $to === $email && str_contains($subject, 'heartbeat'));
    app()->instance(CentralOutboundMailer::class, $mailer);

    event(new InstallationHeartbeatStateChanged(
        $workspace['integration']->id,
        $workspace['tenant']->id,
        WhmcsIntegration::HEARTBEAT_STATE_OK,
        WhmcsIntegration::HEARTBEAT_STATE_DOWN,
        CarbonImmutable::now('UTC'),
    ));

    expect(TenantAlertDelivery::query()->where('status', 'sent')->count())->toBe(1);
});

<?php

use App\Models\Tenant;
use App\Models\TenantAlertChannel;
use App\Models\TenantAlertDelivery;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function createAlertHttpWorkspace(array $entitlements = [], string $role = TenantMembership::ROLE_OWNER): array
{
    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);
    $tenant = Tenant::create([
        'name' => 'Alert HTTP Workspace',
        'slug' => 'alert-http-'.uniqid(),
        'status' => Tenant::STATUS_ACTIVE,
        'created_by_user_id' => $user->id,
    ]);
    $tenant->memberships()->create([
        'user_id' => $user->id,
        'role' => $role,
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
            'limits' => ['integrations' => 5, 'members' => 10, 'diagnostics_per_period' => 100, 'retention_days' => 30],
        ], $entitlements),
        'current_period_starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addMonth(),
    ]);

    return compact('user', 'tenant');
}

it('renders the alerts page for entitled owners', function () {
    $workspace = createAlertHttpWorkspace();

    $this->actingAs($workspace['user'])
        ->get(route('tenants.alerts.index', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/alerts')
            ->where('channelsEntitled', true)
            ->where('canManage', true)
            ->has('eventKeys')
            ->has('channelTypes'));
});

it('shows an upgrade hint when the workspace lacks alerts.channels', function () {
    $workspace = createAlertHttpWorkspace(['alerts.channels' => false]);

    $this->actingAs($workspace['user'])
        ->get(route('tenants.alerts.index', $workspace['tenant']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tenants/alerts')
            ->where('channelsEntitled', false));
});

it('creates a webhook channel with a one-time signing secret and encrypts secrets at rest', function () {
    $workspace = createAlertHttpWorkspace();
    $target = 'https://hooks.example.test/inbound/'.Str::random(12);

    $response = $this->actingAs($workspace['user'])
        ->post(route('tenants.alerts.channels.store', $workspace['tenant']), [
            'type' => 'webhook',
            'name' => 'Ops webhook',
            'target' => $target,
            'events' => ['all_critical'],
            'enabled' => true,
        ]);

    $response->assertRedirect(route('tenants.alerts.index', $workspace['tenant']));
    $secret = $response->getSession()->get('createdSigningSecret.secret');
    expect($secret)->toBeString()->not->toBeEmpty();

    $channel = TenantAlertChannel::query()->where('tenant_id', $workspace['tenant']->id)->first();
    expect($channel)->not->toBeNull()
        ->and($channel->target)->toBe($target)
        ->and($channel->signing_secret)->toBe($secret);

    $rawTarget = DB::table('tenant_alert_channels')->where('id', $channel->id)->value('target');
    $rawSecret = DB::table('tenant_alert_channels')->where('id', $channel->id)->value('signing_secret');

    expect($rawTarget)->not->toBe($target)
        ->and($rawSecret)->not->toBe($secret)
        ->and($channel->toArray())->not->toHaveKey('target')
        ->and($channel->toArray())->not->toHaveKey('signing_secret');
});

it('rejects private webhook targets on create', function () {
    $workspace = createAlertHttpWorkspace();

    $this->actingAs($workspace['user'])
        ->from(route('tenants.alerts.index', $workspace['tenant']))
        ->post(route('tenants.alerts.channels.store', $workspace['tenant']), [
            'type' => 'webhook',
            'name' => 'Bad webhook',
            'target' => 'https://127.0.0.1/hook',
            'events' => ['all_critical'],
            'enabled' => true,
        ])
        ->assertRedirect(route('tenants.alerts.index', $workspace['tenant']))
        ->assertSessionHasErrors('target');

    expect(TenantAlertChannel::query()->where('tenant_id', $workspace['tenant']->id)->count())->toBe(0);
});

it('gates channel management on entitlement and owner/admin role', function () {
    $starter = createAlertHttpWorkspace(['alerts.channels' => false]);

    $this->actingAs($starter['user'])
        ->post(route('tenants.alerts.channels.store', $starter['tenant']), [
            'type' => 'email',
            'name' => 'Ops email',
            'target' => 'ops@example.test',
            'events' => ['all_critical'],
            'enabled' => true,
        ])
        ->assertForbidden();

    $operator = createAlertHttpWorkspace([], TenantMembership::ROLE_OPERATOR);

    $this->actingAs($operator['user'])
        ->post(route('tenants.alerts.channels.store', $operator['tenant']), [
            'type' => 'email',
            'name' => 'Ops email',
            'target' => 'ops@example.test',
            'events' => ['all_critical'],
            'enabled' => true,
        ])
        ->assertForbidden();
});

it('isolates channels across tenants', function () {
    $a = createAlertHttpWorkspace();
    $b = createAlertHttpWorkspace();

    $channel = TenantAlertChannel::query()->create([
        'tenant_id' => $a['tenant']->id,
        'type' => TenantAlertChannel::TYPE_EMAIL,
        'name' => 'A email',
        'target' => 'a@example.test',
        'events' => ['all_critical'],
        'enabled' => true,
    ]);

    $this->actingAs($b['user'])
        ->put(route('tenants.alerts.channels.update', [$b['tenant'], $channel]), [
            'name' => 'Hijacked',
            'events' => ['all_critical'],
            'enabled' => true,
        ])
        ->assertNotFound();

    $this->actingAs($b['user'])
        ->delete(route('tenants.alerts.channels.destroy', [$b['tenant'], $channel]))
        ->assertNotFound();

    expect($channel->fresh()->name)->toBe('A email');
});

it('sends a test alert and records a delivery', function () {
    $workspace = createAlertHttpWorkspace();
    $url = 'https://hooks.example.test/test/'.Str::random(8);
    $channel = TenantAlertChannel::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'type' => TenantAlertChannel::TYPE_SLACK,
        'name' => 'Slack',
        'target' => $url,
        'events' => ['all_critical'],
        'enabled' => true,
    ]);

    Http::fake(['hooks.example.test/*' => Http::response('', 200)]);

    $this->actingAs($workspace['user'])
        ->post(route('tenants.alerts.channels.test', [$workspace['tenant'], $channel]))
        ->assertRedirect(route('tenants.alerts.index', $workspace['tenant']))
        ->assertSessionHas('status');

    expect(TenantAlertDelivery::query()
        ->where('channel_id', $channel->id)
        ->where('alert_key', 'alert.test')
        ->where('status', TenantAlertDelivery::STATUS_SENT)
        ->count())->toBe(1)
        ->and($channel->fresh()->verified_at)->not->toBeNull();
});

it('updates disables and deletes channels', function () {
    $workspace = createAlertHttpWorkspace();
    $channel = TenantAlertChannel::query()->create([
        'tenant_id' => $workspace['tenant']->id,
        'type' => TenantAlertChannel::TYPE_EMAIL,
        'name' => 'Ops',
        'target' => 'ops@example.test',
        'events' => ['all_critical'],
        'enabled' => true,
    ]);

    $this->actingAs($workspace['user'])
        ->put(route('tenants.alerts.channels.update', [$workspace['tenant'], $channel]), [
            'name' => 'Night ops',
            'events' => ['installation.heartbeat.down'],
            'enabled' => false,
        ])
        ->assertRedirect(route('tenants.alerts.index', $workspace['tenant']));

    expect($channel->fresh()->name)->toBe('Night ops')
        ->and($channel->fresh()->enabled)->toBeFalse()
        ->and($channel->fresh()->events)->toBe(['installation.heartbeat.down']);

    $this->actingAs($workspace['user'])
        ->delete(route('tenants.alerts.channels.destroy', [$workspace['tenant'], $channel]))
        ->assertRedirect(route('tenants.alerts.index', $workspace['tenant']));

    expect(TenantAlertChannel::query()->whereKey($channel->id)->exists())->toBeFalse();
});

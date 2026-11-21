<?php

use App\Billing\Kelviq\KelviqBillingProviderAdapter;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\BillingGatewaySetting;
use App\Models\CentralStaffAccess;
use App\Models\User;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\PaymentGatewayManager;
use App\Services\CentralStaffAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'operations.owner_email' => 'owner@example.test',
        'billing.default_gateway' => 'sandbox',
        'billing.kelviq.mode' => 'sandbox',
        'billing.kelviq.api_key' => 'kq_env_api_key',
        'billing.kelviq.webhook_secret' => 'kq_whsec_env_secret',
        'billing.kelviq.sandbox_base_url' => 'https://sandboxapi.kelviq.com/api/v1',
        'billing.kelviq.live_base_url' => 'https://api.kelviq.com/api/v1',
    ]);
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
});

function billingSettingsOwner(): User
{
    return User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
}

function billingSettingsStaff(array $permissions): User
{
    $owner = User::factory()->create(['email' => 'grantor-'.uniqid().'@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => $permissions,
        'granted_by_user_id' => $owner->id,
    ]);

    return $staff;
}

it('allows the super admin to view billing settings', function () {
    $owner = billingSettingsOwner();

    $this->actingAs($owner)
        ->get(route('platform.billing.settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/settings')
            ->has('settings.gateway')
            ->has('settings.kelviq_sandbox_api_key.mask')
            ->where('settings.kelviq_sandbox_api_key.configured', true)
            ->etc());
});

it('allows staff with billing.settings.manage to view billing settings', function () {
    $staff = billingSettingsStaff(['billing.settings.manage']);

    $this->actingAs($staff)
        ->get(route('platform.billing.settings.edit'))
        ->assertOk();
});

it('forbids staff who only have the billing permission', function () {
    $staff = billingSettingsStaff(['billing']);

    $this->actingAs($staff)
        ->get(route('platform.billing.settings.edit'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('platform.billing.settings.edit'))
        ->assertRedirect();
});

it('prevents non-super-admins from granting billing.settings.manage', function () {
    $staff = billingSettingsStaff(['billing', 'settings']);
    $target = User::factory()->withTwoFactor()->create();
    $access = CentralStaffAccess::query()->create([
        'user_id' => $target->id,
        'permissions' => ['billing'],
        'granted_by_user_id' => $staff->id,
    ]);

    $this->actingAs($staff)
        ->put('/platform/staff/'.$access->id, [
            'permissions' => ['billing', 'billing.settings.manage'],
        ])
        ->assertForbidden();

    expect($access->fresh()->permissions)->toBe(['billing']);
});

it('encrypts secrets at rest and decrypts through the model', function () {
    $owner = billingSettingsOwner();
    $plaintext = 'kq_secret_plaintext_abcd';

    $this->actingAs($owner)->put(route('platform.billing.settings.update'), [
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => $plaintext,
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ])->assertRedirect(route('platform.billing.settings.edit'));

    $row = BillingGatewaySetting::query()->findOrFail(1);
    $raw = $row->getRawOriginal('kelviq_sandbox_api_key');

    expect($raw)->not->toBe($plaintext)
        ->and($raw)->not->toContain('kq_secret_plaintext')
        ->and($row->kelviq_sandbox_api_key)->toBe($plaintext);
});

it('sends only masked secret props to the browser', function () {
    $owner = billingSettingsOwner();
    $plaintext = 'kq_ui_secret_9999';

    BillingGatewaySetting::query()->create([
        'id' => 1,
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => $plaintext,
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('platform.billing.settings.edit'))
        ->assertOk()
        ->assertDontSee($plaintext)
        ->assertInertia(fn ($page) => $page
            ->where('settings.kelviq_sandbox_api_key.mask', '••••9999')
            ->where('settings.kelviq_sandbox_api_key.configured', true)
            ->where('settings.kelviq_sandbox_api_key.source', 'database')
            ->etc());
});

it('keeps the stored secret when the input is blank', function () {
    $owner = billingSettingsOwner();
    BillingGatewaySetting::query()->create([
        'id' => 1,
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => 'kq_keep_me_1234',
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ]);

    $this->actingAs($owner)->put(route('platform.billing.settings.update'), [
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => '',
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ])->assertRedirect();

    expect(BillingGatewaySetting::query()->findOrFail(1)->kelviq_sandbox_api_key)
        ->toBe('kq_keep_me_1234');
});

it('clears a stored secret and falls back to env', function () {
    $owner = billingSettingsOwner();
    BillingGatewaySetting::query()->create([
        'id' => 1,
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => 'kq_db_only_key',
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ]);

    $this->actingAs($owner)->put(route('platform.billing.settings.update'), [
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'clear_kelviq_sandbox_api_key' => true,
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ])->assertRedirect();

    $row = BillingGatewaySetting::query()->findOrFail(1);
    expect($row->kelviq_sandbox_api_key)->toBeNull()
        ->and(app(BillingSettings::class)->kelviqApiKey())->toBe('kq_env_api_key')
        ->and(app(BillingSettings::class)->source('kelviq_sandbox_api_key'))->toBe('env');
});

it('records an audit event with field names and never the secret value', function () {
    $owner = billingSettingsOwner();
    $secret = 'kq_audit_secret_value_xyz';

    $this->actingAs($owner)->put(route('platform.billing.settings.update'), [
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => $secret,
        'trials_enabled' => true,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ])->assertRedirect();

    $event = DB::table('audit_events')->where('event_type', 'billing.settings.updated')->first();
    expect($event)->not->toBeNull();

    $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);
    expect($metadata['changed_fields'])->toHaveKey('gateway')
        ->and($metadata['changed_fields'])->toHaveKey('kelviq_sandbox_api_key')
        ->and($metadata['changed_fields']['kelviq_sandbox_api_key'])->toBe(['action' => 'replaced'])
        ->and(json_encode($metadata))->not->toContain($secret);
});

it('resolves gateway mode and secrets with DB over env over defaults', function () {
    config()->set('billing.default_gateway', null);
    config()->set('billing.kelviq.mode', null);
    config()->set('billing.kelviq.api_key', '');
    config()->set('billing.kelviq.webhook_secret', '');

    $settings = app(BillingSettings::class);
    expect($settings->gateway())->toBe('sandbox')
        ->and($settings->kelviqMode())->toBe('sandbox')
        ->and($settings->kelviqApiKey())->toBe('')
        ->and($settings->source('gateway'))->toBe('env');

    config()->set('billing.default_gateway', 'kelviq');
    config()->set('billing.kelviq.mode', 'live');
    config()->set('billing.kelviq.api_key', 'kq_env_live');
    config()->set('billing.kelviq.webhook_secret', 'kq_whsec_env_live');

    expect($settings->gateway())->toBe('kelviq')
        ->and($settings->kelviqMode())->toBe('live')
        ->and($settings->kelviqApiKey())->toBe('kq_env_live')
        ->and($settings->kelviqWebhookSecret())->toBe('kq_whsec_env_live')
        ->and($settings->source('kelviq_live_api_key'))->toBe('env');

    BillingGatewaySetting::query()->create([
        'id' => 1,
        'gateway' => 'sandbox',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => 'kq_db_sandbox',
        'kelviq_sandbox_webhook_secret' => 'kq_whsec_db_sandbox',
        'kelviq_live_api_key' => 'kq_db_live',
        'kelviq_live_webhook_secret' => 'kq_whsec_db_live',
        'trials_enabled' => true,
        'trial_card_required' => false,
        'trial_first_time_only' => false,
    ]);

    expect($settings->gateway())->toBe('sandbox')
        ->and($settings->kelviqMode())->toBe('sandbox')
        ->and($settings->kelviqApiKey())->toBe('kq_db_sandbox')
        ->and($settings->kelviqWebhookSecret())->toBe('kq_whsec_db_sandbox')
        ->and($settings->kelviqApiKeyForMode('live'))->toBe('kq_db_live')
        ->and($settings->source('gateway'))->toBe('database')
        ->and($settings->trialsEnabled())->toBeTrue()
        ->and($settings->trialCardRequired())->toBeFalse()
        ->and($settings->trialFirstTimeOnly())->toBeFalse();
});

it('tolerates a missing billing_gateway_settings table by falling back to config', function () {
    Schema::dropIfExists('billing_gateway_settings');
    config()->set('billing.default_gateway', 'kelviq');
    config()->set('billing.kelviq.api_key', 'kq_pre_migration');

    expect(app(BillingSettings::class)->gateway())->toBe('kelviq')
        ->and(app(BillingSettings::class)->kelviqApiKey())->toBe('kq_pre_migration');
});

it('verifies webhooks with the database secret when set', function () {
    BillingGatewaySetting::query()->create([
        'id' => 1,
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_webhook_secret' => 'kq_whsec_db_verify',
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ]);

    $body = json_encode([
        'id' => '22222222-2222-2222-2222-222222222222',
        'type' => 'customer.created',
        'created_at' => now('UTC')->toIso8601String(),
        'data' => ['object' => ['customer_id' => 'acct_kelviq_test']],
    ], JSON_THROW_ON_ERROR);
    $eventId = '22222222-2222-2222-2222-222222222222';
    $timestamp = (string) now('UTC')->timestamp;
    $signature = 'v1,'.hash_hmac('sha256', $eventId.'.'.$timestamp.'.'.$body, 'kq_whsec_db_verify');

    $verified = app(KelviqBillingProviderAdapter::class)->verifyAndNormalize($body, [
        'webhook-id' => $eventId,
        'webhook-timestamp' => $timestamp,
        'webhook-signature' => $signature,
    ]);

    expect($verified->eventType)->toBe('webhook.ignored');

    expect(fn () => app(KelviqBillingProviderAdapter::class)->verifyAndNormalize($body, [
        'webhook-id' => $eventId,
        'webhook-timestamp' => $timestamp,
        'webhook-signature' => 'v1,'.hash_hmac('sha256', $eventId.'.'.$timestamp.'.'.$body, 'kq_whsec_env_secret'),
    ]))->toThrow(RuntimeException::class, 'billing_kelviq_signature_invalid');
});

it('tests the connection with Http::fake and never leaks the key', function () {
    $owner = billingSettingsOwner();
    $secret = 'kq_test_connection_secret_leak_check';

    BillingGatewaySetting::query()->create([
        'id' => 1,
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => $secret,
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://sandboxapi.kelviq.com/api/v1/webhook/logs/*' => Http::response(['items' => []], 200),
    ]);

    $response = $this->actingAs($owner)
        ->post(route('platform.billing.settings.test'));

    $response->assertRedirect(route('platform.billing.settings.edit'))
        ->assertSessionHas('connection_test.ok', true)
        ->assertSessionMissing('connection_test.message', $secret);

    expect(json_encode(session('connection_test')))->not->toContain($secret);

    $audit = DB::table('audit_events')->where('event_type', 'billing.settings.connection_tested')->first();
    expect($audit)->not->toBeNull();
    $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
    expect($metadata)->toMatchArray(['ok' => true, 'status' => 200])
        ->and(json_encode($metadata))->not->toContain($secret);

    Http::assertSent(function ($request) use ($secret) {
        return str_contains($request->url(), '/webhook/logs/')
            && str_contains($request->url(), 'page_size=1')
            && $request->hasHeader('Authorization', 'Bearer '.$secret);
    });
});

it('lets PaymentGatewayManager pick the database gateway', function () {
    config()->set('billing.default_gateway', 'sandbox');
    config()->set('billing.kelviq.api_key', 'kq_env_api_key');
    config()->set('billing.kelviq.webhook_secret', 'kq_whsec_env_secret');

    BillingGatewaySetting::query()->create([
        'id' => 1,
        'gateway' => 'kelviq',
        'kelviq_mode' => 'sandbox',
        'kelviq_sandbox_api_key' => 'kq_db_driver_key',
        'kelviq_sandbox_webhook_secret' => 'kq_whsec_db',
        'trials_enabled' => false,
        'trial_card_required' => true,
        'trial_first_time_only' => true,
    ]);

    $gateway = app(PaymentGatewayManager::class)->driver();

    expect($gateway->driver())->toBe('kelviq')
        ->and(app(BillingSettings::class)->gateway())->toBe('kelviq');
});

it('includes billing.settings.manage in the central permission vocabulary for the super admin', function () {
    $owner = billingSettingsOwner();

    expect(app(CentralStaffAuthorization::class)->can($owner, 'billing.settings.manage'))->toBeTrue()
        ->and(CentralStaffAuthorization::PERMISSIONS)->toContain('billing.settings.manage');
});

<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\AuditEvent;
use App\Models\CentralStaffAccess;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\PlatformSettings\PlatformSettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['operations.owner_email' => 'owner@example.test']);
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
    $this->withoutVite();
});

function platformSettingsOwner(): User
{
    return User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
}

function platformSettingsStaff(array $permissions): User
{
    $owner = User::factory()->create(['email' => 'grantor-'.uniqid('', true).'@example.test']);
    $staff = User::factory()->withTwoFactor()->create();
    CentralStaffAccess::query()->create([
        'user_id' => $staff->id,
        'permissions' => $permissions,
        'granted_by_user_id' => $owner->id,
    ]);

    return $staff;
}

it('exposes registry defaults that match current config values', function () {
    $registry = app(PlatformSettingsRegistry::class);

    foreach ($registry->all() as $definition) {
        expect($definition->resolveDefault())
            ->toEqual(config($definition->key));
    }
});

it('returns config values until a database override exists', function () {
    config(['security.two_factor_required' => false]);
    $settings = app(PlatformSettings::class);

    expect($settings->get('security.two_factor_required'))->toBeFalse()
        ->and($settings->source('security.two_factor_required'))->toBe('env');

    $settings->set('security.two_factor_required', true, platformSettingsOwner());

    expect($settings->get('security.two_factor_required'))->toBeTrue()
        ->and($settings->source('security.two_factor_required'))->toBe('database')
        ->and(config('security.two_factor_required'))->toBeTrue();
});

it('resets a key back to the config fallback', function () {
    config(['fixes.bulk_max_items' => 50]);
    $settings = app(PlatformSettings::class);
    $owner = platformSettingsOwner();

    $settings->set('fixes.bulk_max_items', 12, $owner);
    expect($settings->get('fixes.bulk_max_items'))->toBe(12);

    $settings->forget('fixes.bulk_max_items', $owner);

    expect($settings->get('fixes.bulk_max_items'))->toBe(50)
        ->and($settings->source('fixes.bulk_max_items'))->toBe('env')
        ->and(PlatformSetting::query()->where('key', 'fixes.bulk_max_items')->exists())->toBeFalse();
});

it('encrypts secrets at rest and never returns them in inertia props', function () {
    $owner = platformSettingsOwner();
    $secret = 'https://hooks.example.test/'.bin2hex(random_bytes(16));

    $this->actingAs($owner)
        ->put(route('platform.settings.update', 'alerts'), [
            'settings' => [
                'operations.alerts.slack_webhook_url' => $secret,
            ],
        ])
        ->assertRedirect(route('platform.settings.show', ['group' => 'alerts']));

    $row = PlatformSetting::query()->findOrFail('operations.alerts.slack_webhook_url');
    $raw = (string) $row->getAttributes()['value'];

    expect($raw)->not->toContain($secret)
        ->and($row->is_secret)->toBeTrue()
        ->and($row->decodedValue())->toBe($secret);

    $this->actingAs($owner)
        ->get(route('platform.settings.show', ['group' => 'alerts']))
        ->assertOk()
        ->assertDontSee($secret)
        ->assertInertia(fn ($page) => $page
            ->component('platform/settings')
            ->where('settings.alerts', function ($fields) use ($secret) {
                $slack = collect($fields)->firstWhere('key', 'operations.alerts.slack_webhook_url');

                return $slack !== null
                    && $slack['is_set'] === true
                    && $slack['value'] === null
                    && ! str_contains((string) json_encode($fields), $secret);
            })
        );
});

it('allows central settings permission and forbids tenant users', function () {
    $staff = platformSettingsStaff(['settings']);
    $tenantUser = User::factory()->withTwoFactor()->create([
        'email' => 'tenant-user-'.uniqid('', true).'@example.test',
    ]);

    $this->actingAs($staff)
        ->get(route('platform.settings.show'))
        ->assertOk();

    $this->actingAs($tenantUser)
        ->get(route('platform.settings.show'))
        ->assertForbidden();

    $this->actingAs($tenantUser)
        ->put(route('platform.settings.update', 'security'), [
            'settings' => ['security.registration_open' => false],
        ])
        ->assertForbidden();
});

it('validates settings against the registry', function () {
    $owner = platformSettingsOwner();

    $this->actingAs($owner)
        ->from(route('platform.settings.show', ['group' => 'reports']))
        ->put(route('platform.settings.update', 'reports'), [
            'settings' => [
                'reports.weekly_day' => 99,
            ],
        ])
        ->assertRedirect(route('platform.settings.show', ['group' => 'reports']))
        ->assertSessionHasErrors('settings.reports.weekly_day');
});

it('writes an audit event without secret values', function () {
    $owner = platformSettingsOwner();
    $secret = 'https://discord.example.test/'.bin2hex(random_bytes(12));

    $this->actingAs($owner)
        ->put(route('platform.settings.update', 'alerts'), [
            'settings' => [
                'operations.alerts.discord_webhook_url' => $secret,
            ],
        ])
        ->assertRedirect();

    $event = AuditEvent::query()
        ->where('event_type', 'platform.settings.updated')
        ->where('subject_id', 'operations.alerts.discord_webhook_url')
        ->latest('chain_sequence')
        ->first();

    expect($event)->not->toBeNull();

    $metadata = is_string($event->metadata)
        ? json_decode($event->metadata, true)
        : $event->metadata;

    expect($metadata['key'])->toBe('operations.alerts.discord_webhook_url')
        ->and($metadata['changed'])->toBeTrue()
        ->and($metadata['is_secret'])->toBeTrue()
        ->and(json_encode($metadata))->not->toContain($secret);
});

it('overlays database values onto config for consumers', function () {
    $settings = app(PlatformSettings::class);
    $settings->set('billing.invoice_number_prefix', 'OPS', platformSettingsOwner());

    app()->forgetInstance(PlatformSettings::class);
    app(PlatformSettings::class)->overlayOntoConfig();

    expect(config('billing.invoice_number_prefix'))->toBe('OPS');
});

it('is safe to overlay when the platform_settings table is missing', function () {
    Schema::dropIfExists('platform_settings');
    app()->forgetInstance(PlatformSettings::class);

    expect(fn () => app(PlatformSettings::class)->overlayOntoConfig())
        ->not->toThrow(Throwable::class);
});

it('resets a key through the http endpoint', function () {
    $owner = platformSettingsOwner();
    $settings = app(PlatformSettings::class);
    $settings->set('monitoring.heartbeat_stale_minutes', 90, $owner);

    $this->actingAs($owner)
        ->delete(route('platform.settings.reset', ['key' => 'monitoring.heartbeat_stale_minutes']))
        ->assertRedirect(route('platform.settings.show', ['group' => 'monitoring']));

    expect($settings->source('monitoring.heartbeat_stale_minutes'))->toBe('env')
        ->and(AuditEvent::query()->where('event_type', 'platform.settings.reset')->exists())->toBeTrue();
});

it('stores secret ciphertext that decrypts with the application key', function () {
    $settings = app(PlatformSettings::class);
    $value = 'https://sms.example.test/'.bin2hex(random_bytes(8));
    $settings->set('operations.alerts.critical_sms_webhook_url', $value, platformSettingsOwner());

    $raw = DB::table('platform_settings')
        ->where('key', 'operations.alerts.critical_sms_webhook_url')
        ->value('value');

    expect($raw)->not->toBe($value)
        ->and(json_decode(Crypt::decryptString((string) $raw), true))->toBe($value);
});

<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\PlatformAiSettings;
use App\Models\User;
use App\Services\Ai\OpenAiConfiguration;
use App\Support\WhmcsAddonPackageSigner;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
});

it('forbids ai settings for non-settings staff', function () {
    config(['operations.owner_email' => 'owner@example.test']);
    $user = User::factory()->withTwoFactor()->create(['email' => 'other@example.test']);

    $this->actingAs($user)->get('/platform/ai-settings')->assertForbidden();
});

it('lets the platform owner save openai settings without exposing the api key', function () {
    config(['operations.owner_email' => 'owner@example.test']);
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);

    $this->actingAs($owner)->put('/platform/ai-settings', [
        'api_key' => 'sk-test-secret-key',
        'model' => 'gpt-4o',
        'reasoning_effort' => 'high',
        'timeout' => 60,
    ])->assertRedirect(route('platform.ai-settings.show'));

    $row = PlatformAiSettings::query()->findOrFail(1);
    expect($row->getRawOriginal('configuration'))->not->toContain('sk-test-secret-key')
        ->and(app(OpenAiConfiguration::class)->apiKey())->toBe('sk-test-secret-key')
        ->and(app(OpenAiConfiguration::class)->model())->toBe('gpt-4o')
        ->and(app(OpenAiConfiguration::class)->reasoningEffort())->toBe('high');

    $this->actingAs($owner)
        ->get('/platform/ai-settings')
        ->assertOk()
        ->assertDontSee('sk-test-secret-key')
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/ai-settings')
            ->where('api_key_set', true)
            ->where('model', 'gpt-4o'));
});

it('generates a signing key pair from the platform ui', function () {
    config(['operations.owner_email' => 'owner@example.test']);
    $owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);

    $this->actingAs($owner)
        ->post('/platform/ai-settings/generate-signing-keys')
        ->assertRedirect(route('platform.ai-settings.show'));

    expect(app(WhmcsAddonPackageSigner::class)->isConfigured())->toBeTrue()
        ->and(app(OpenAiConfiguration::class)->signingPublicKey())->not->toBe('');
});

it('renders the customer-facing addon setup page without 20i copy', function () {
    $user = User::factory()->withTwoFactor()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get('/whmcs-addon/setup')
        ->assertOk()
        ->assertDontSee('20i')
        ->assertInertia(fn (Assert $page) => $page
            ->component('whmcs-addon/setup')
            ->has('addonVersion')
            ->has('downloadAvailable')
            ->has('aiConfigured'));
});

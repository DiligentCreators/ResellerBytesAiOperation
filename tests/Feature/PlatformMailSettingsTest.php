<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\PlatformMailSettings;
use App\Models\User;
use App\Services\Mail\CentralOutboundMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PlatformMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureTwoFactorEnabled::class);
    }

    public function test_only_designated_platform_owner_can_view_mail_settings(): void
    {
        config(['operations.owner_email' => 'owner@example.test']);
        $user = User::factory()->create(['email' => 'other@example.test']);
        $this->actingAs($user)->get('/platform/mail-settings')->assertForbidden();
    }

    public function test_secrets_are_encrypted_and_never_returned_to_settings_page(): void
    {
        config(['operations.owner_email' => 'owner@example.test']);
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $this->actingAs($user)->put('/platform/mail-settings', [
            'driver' => 'postmark',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
            'postmark_server_token' => 'private-token-value',
            'postmark_message_stream' => 'outbound',
        ])->assertRedirect();

        $row = PlatformMailSettings::query()->findOrFail(1);
        $this->assertStringNotContainsString('private-token-value', $row->getRawOriginal('configuration'));
        $this->actingAs($user)->get('/platform/mail-settings')->assertOk()->assertDontSee('private-token-value');
    }


    public function test_smtp_username_and_password_are_encrypted_and_never_returned_to_the_ui(): void
    {
        config(['operations.owner_email' => 'owner@example.test']);
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->actingAs($user)->put('/platform/mail-settings', [
            'driver' => 'smtp',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'private-smtp-user',
            'smtp_password' => 'private-smtp-password',
        ])->assertRedirect();

        $row = PlatformMailSettings::query()->findOrFail(1);
        $raw = $row->getRawOriginal('configuration');
        $this->assertStringNotContainsString('private-smtp-user', $raw);
        $this->assertStringNotContainsString('private-smtp-password', $raw);

        $this->actingAs($user)->get('/platform/mail-settings')
            ->assertOk()
            ->assertDontSee('private-smtp-user')
            ->assertDontSee('private-smtp-password')
            ->assertDontSee('value="private-smtp-user"');
    }

    public function test_postmark_environment_configuration_is_used_when_no_database_settings_exist(): void
    {
        config([
            'platform_mail.driver' => 'postmark',
            'platform_mail.from_address' => 'env-mail@example.test',
            'platform_mail.from_name' => 'Environment Sender',
            'platform_mail.postmark.server_token' => 'environment-token',
            'platform_mail.postmark.message_stream' => 'outbound',
        ]);
        Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'fake'], 200)]);

        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Postmark-Server-Token', 'environment-token')
            && $request['From'] === 'Environment Sender <env-mail@example.test>');
    }

    public function test_postmark_driver_uses_saved_encrypted_configuration(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'postmark', 'configuration' => [
            'server_token' => 'fake-token',
            'message_stream' => 'outbound',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
        ]]);
        Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'fake'], 200)]);
        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Postmark-Server-Token', 'fake-token'));
    }

    public function test_smtp_driver_uses_saved_settings_with_a_fake_mailer(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'smtp', 'configuration' => [
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer-user',
            'password' => 'smtp-secret',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
        ]]);

        $fakeMailer = \Mockery::mock();
        $fakeMailer->shouldReceive('html')->once();
        Mail::shouldReceive('build')->once()
            ->with(\Mockery::on(fn (array $config): bool => $config['host'] === 'smtp.example.test'
                && $config['port'] === 587
                && $config['username'] === 'mailer-user'
                && $config['password'] === 'smtp-secret'))
            ->andReturn($fakeMailer);

        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');
    }

    public function test_log_driver_never_contacts_external_transport(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'log', 'configuration' => []]);
        Http::fake();
        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Mail\OperationalAlertMail;
use App\Models\PlatformMailSettings;
use App\Models\User;
use App\Services\Mail\CentralOutboundMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_postmark_environment_configuration_fills_missing_database_secrets(): void
    {
        PlatformMailSettings::query()->updateOrCreate(['id' => 1], ['driver' => 'postmark', 'configuration' => []]);
        Http::swap(new Factory);
        config([
            'platform_mail.driver' => 'postmark',
            'platform_mail.from_address' => 'env-mail@example.test',
            'platform_mail.from_name' => 'Environment Sender',
            'platform_mail.postmark.server_token' => 'environment-token',
            'platform_mail.postmark.message_stream' => 'outbound',
        ]);
        Http::swap(new Factory);
        Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'fake'], 200)]);

        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.postmarkapp.com/email');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Postmark-Server-Token')
            && $request['From'] === '"Environment Sender" <env-mail@example.test>');
    }

    public function test_postmark_driver_uses_saved_encrypted_configuration(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'postmark', 'configuration' => [
            'server_token' => 'fake-token',
            'message_stream' => 'outbound',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
        ]]);
        Http::swap(new Factory);
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

    public function test_laravel_transactional_mailables_use_the_central_platform_transport(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'postmark', 'configuration' => [
            'server_token' => 'central-token',
            'message_stream' => 'outbound',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
        ]]);
        Http::swap(new Factory);
        Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'fake'], 200)]);

        Mail::to('recipient@example.test')->send(
            new OperationalAlertMail('warning', 'test-alert', 'Test alert body'),
        );

        Http::assertSent(fn ($request) => $request->hasHeader('X-Postmark-Server-Token', 'central-token')
            && $request['To'] === 'recipient@example.test');
    }

    public function test_send_test_email_action_reports_log_preview_without_sending(): void
    {
        config(['operations.owner_email' => 'owner@example.test', 'platform_mail.driver' => 'log']);
        $user = User::factory()->create(['email' => 'owner@example.test']);
        Http::swap(new Factory);
        Http::fake();

        $this->actingAs($user)->post('/platform/mail-settings/test', [
            'recipient' => 'recipient@example.test',
        ])->assertRedirect()->assertSessionHas('status', 'Log driver is active; a redacted preview was recorded and no email was sent.');

        Http::assertNothingSent();
    }

    public function test_clearing_postmark_token_blocks_environment_fallback(): void
    {
        config([
            'operations.owner_email' => 'owner@example.test',
            'platform_mail.postmark.server_token' => 'environment-token',
        ]);
        $user = User::factory()->create(['email' => 'owner@example.test']);
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'postmark', 'configuration' => [
            'server_token' => 'saved-token',
            'message_stream' => 'outbound',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
        ]]);

        $this->actingAs($user)->put('/platform/mail-settings', [
            'driver' => 'postmark',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
            'postmark_message_stream' => 'outbound',
            'clear_postmark_token' => '1',
        ])->assertSessionHasErrors('postmark_server_token');

        $this->assertSame('saved-token', PlatformMailSettings::query()->findOrFail(1)->configuration['server_token']);
    }

    public function test_cleared_smtp_credentials_do_not_reappear_from_environment(): void
    {
        config([
            'operations.owner_email' => 'owner@example.test',
            'platform_mail.smtp.username' => 'environment-user',
            'platform_mail.smtp.password' => 'environment-password',
        ]);
        $user = User::factory()->create(['email' => 'owner@example.test']);
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'smtp', 'configuration' => [
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'saved-user',
            'password' => 'saved-password',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
        ]]);

        $this->actingAs($user)->put('/platform/mail-settings', [
            'driver' => 'smtp',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'clear_smtp_username' => '1',
            'clear_smtp_password' => '1',
        ])->assertRedirect();

        $fakeMailer = \Mockery::mock();
        $fakeMailer->shouldReceive('html')->once();
        Mail::shouldReceive('build')->once()
            ->with(\Mockery::on(fn (array $config): bool => $config['username'] === null
                && $config['password'] === null))
            ->andReturn($fakeMailer);

        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');
    }

    public function test_log_driver_never_contacts_external_transport_or_logs_message_content(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'log', 'configuration' => []]);
        Http::swap(new Factory);
        Http::fake();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'outbound_mail.preview'
                && ($context['driver'] ?? null) === 'log'
                && ($context['to'] ?? null) === 'recipient@example.test'
                && ($context['subject'] ?? null) === 'sensitive-subject'
                && ! array_key_exists('html', $context)
                && ! array_key_exists('text', $context)
                && ! array_key_exists('verification_url', $context);
        });
        app(CentralOutboundMailer::class)->send('recipient@example.test', 'sensitive-subject', '<p>private body</p>', 'private body');
        Http::assertNothingSent();
    }

    public function test_smtp_rejects_unencrypted_transport_configuration(): void
    {
        config(['operations.owner_email' => 'owner@example.test']);
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->actingAs($user)->put('/platform/mail-settings', [
            'driver' => 'smtp',
            'from_address' => 'mail@example.test',
            'from_name' => 'ResellerBytes',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 25,
            'smtp_encryption' => 'none',
        ])->assertSessionHasErrors('smtp_encryption');
    }
}

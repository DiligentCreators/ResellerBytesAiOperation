<?php

namespace Tests\Feature;

use App\Models\PlatformMailSettings;
use App\Models\User;
use App\Services\Mail\CentralOutboundMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformMailSettingsTest extends TestCase
{
    use RefreshDatabase;\n\n    protected function setUp(): void\n    {\n        parent::setUp();\n        $this->withoutMiddleware(EnsureTwoFactorEnabled::class);\n    }

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
            'driver' => 'postmark', 'from_address' => 'mail@example.test', 'from_name' => 'ResellerBytes',
            'postmark_server_token' => 'private-token-value', 'postmark_message_stream' => 'outbound',
        ])->assertRedirect();

        $row = PlatformMailSettings::query()->findOrFail(1);
        $this->assertStringNotContainsString('private-token-value', $row->getRawOriginal('configuration'));
        $this->actingAs($user)->get('/platform/mail-settings')->assertOk()->assertDontSee('private-token-value');
    }

    public function test_postmark_driver_uses_saved_encrypted_configuration(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'postmark', 'configuration' => [
            'server_token' => 'fake-token', 'message_stream' => 'outbound',
            'from_address' => 'mail@example.test', 'from_name' => 'ResellerBytes',
        ]]);
        Http::fake(['https://api.postmarkapp.com/email' => Http::response(['MessageID' => 'fake'], 200)]);
        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Postmark-Server-Token', 'fake-token'));
    }

    public function test_log_driver_never_contacts_external_transport(): void
    {
        PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'log', 'configuration' => []]);
        Http::fake();
        app(CentralOutboundMailer::class)->send('recipient@example.test', 'Test', '<p>test</p>', 'test');
        Http::assertNothingSent();
    }
}

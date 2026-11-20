<?php

use App\Models\PlatformMailSettings;
use App\Services\Mail\CentralOutboundMailer;
use Illuminate\Support\Facades\Log;

it('logs verification and password reset URLs for the log mailer in non-production', function () {
    PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'log', 'configuration' => []]);

    $verifyUrl = 'http://resellerbytesaioperations.test/email/verify/1/abc?expires=1&signature=sig';
    $resetUrl = 'http://resellerbytesaioperations.test/reset-password/token-value?email=user%40example.test';

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($verifyUrl, $resetUrl): bool {
            return $message === 'outbound_mail.preview'
                && ($context['driver'] ?? null) === 'log'
                && ($context['to'] ?? null) === 'user@example.test'
                && ($context['verification_url'] ?? null) === $verifyUrl
                && ($context['password_reset_url'] ?? null) === $resetUrl
                && ! array_key_exists('html', $context);
        });

    app(CentralOutboundMailer::class)->send(
        'user@example.test',
        'Verify Email Address',
        '<p><a href="'.$verifyUrl.'">Verify</a></p><p>'.$resetUrl.'</p>',
        "Verify: {$verifyUrl}\nReset: {$resetUrl}",
    );
});

it('never logs verification URLs when the log mailer runs in production', function () {
    $this->app['env'] = 'production';
    PlatformMailSettings::query()->create(['id' => 1, 'driver' => 'log', 'configuration' => []]);

    $verifyUrl = 'https://example.test/email/verify/1/abc?expires=1&signature=sig';

    Log::shouldReceive('info')
        ->once()
        ->with('outbound_mail.preview', ['driver' => 'log']);

    app(CentralOutboundMailer::class)->send(
        'user@example.test',
        'Verify Email Address',
        '<p><a href="'.$verifyUrl.'">Verify</a></p>',
        $verifyUrl,
    );
});

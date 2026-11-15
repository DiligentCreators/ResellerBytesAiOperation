<?php

declare(strict_types=1);

use App\Mail\OperationalAlertMail;
use App\Services\Operations\AlertNotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'operations.alerts.email' => 'owner@example.test',
        'platform_mail.driver' => 'smtp',
        'operations.alerts.slack_webhook_url' => 'https://hooks.slack.test/alerts',
        'operations.alerts.discord_webhook_url' => null,
        'operations.alerts.critical_whatsapp_webhook_url' => null,
        'operations.alerts.critical_sms_webhook_url' => null,
    ]);
});


it('does not mark the log-only mail driver as an email delivery', function () {
    config(['platform_mail.driver' => 'log']);
    Mail::fake();
    Http::fake(['https://hooks.slack.test/*' => Http::response([], 200)]);

    $result = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'error_rate_exceeded',
        'severity' => 'alert',
        'observed' => 2.5,
        'threshold' => 2,
        'window_minutes' => 5,
    ], CarbonImmutable::parse('2026-11-14T12:00:00Z'));

    expect($result['success'])->toBeFalse()
        ->and($result['deliveries']['email'])->toBe('not_sent')
        ->and($result['deliveries']['team_channel'])->toBe('delivered');

    Mail::assertNothingSent();
});

it('delivers standard alerts to owner email and exactly one configured team channel', function () {
    Mail::fake();
    Http::fake(['https://hooks.slack.test/*' => Http::response([], 200)]);

    $result = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'error_rate_exceeded',
        'severity' => 'alert',
        'observed' => 2.5,
        'threshold' => 2,
        'window_minutes' => 5,
    ], CarbonImmutable::parse('2026-11-14T12:00:00Z'));

    expect($result['success'])->toBeTrue()
        ->and($result['deliveries'])->toBe([
            'email' => 'delivered',
            'team_channel' => 'delivered',
        ]);

    Mail::assertSent(OperationalAlertMail::class);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://hooks.slack.test/alerts'
        && str_contains((string) $request['text'], 'error_rate_exceeded'));
});

it('routes critical alerts to every configured critical webhook and requires provider acknowledgement', function () {
    Mail::fake();
    config([
        'operations.alerts.critical_whatsapp_webhook_url' => 'https://notify.example.test/whatsapp',
        'operations.alerts.critical_sms_webhook_url' => 'https://notify.example.test/sms',
    ]);
    Http::fake([
        'https://hooks.slack.test/*' => Http::response([], 204),
        'https://notify.example.test/whatsapp' => Http::response([], 202),
        'https://notify.example.test/sms' => Http::response([], 200),
    ]);

    $result = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'disk_usage_critical',
        'severity' => 'critical',
        'observed' => 92,
        'threshold' => 90,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['deliveries']['whatsapp'])->toBe('delivered')
        ->and($result['deliveries']['sms'])->toBe('delivered');

    Http::assertSentCount(3);
});

it('does not follow webhook redirects to a second destination', function () {
    Mail::fake();
    Http::fake([
        'https://hooks.slack.test/alerts' => Http::response('', 302, [
            'Location' => 'https://internal.example.test/redirect-target',
        ]),
    ]);

    $result = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'error_rate_exceeded',
        'severity' => 'alert',
        'observed' => 3,
        'threshold' => 2,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['deliveries']['team_channel'])->toBe('failed');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'internal.example.test'));
});

it('does not report delivery when a required route is missing or a webhook does not acknowledge', function () {
    Mail::fake();
    config([
        'operations.alerts.slack_webhook_url' => null,
        'operations.alerts.discord_webhook_url' => null,
    ]);
    Http::fake(['https://hooks.slack.test/*' => Http::response([], 503)]);

    $missing = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'queue_job_age_exceeded',
        'severity' => 'alert',
        'observed' => 20,
        'threshold' => 15,
    ]);

    expect($missing['success'])->toBeFalse()
        ->and($missing['deliveries']['team_channel'])->toBe('missing');

    config(['operations.alerts.slack_webhook_url' => 'https://hooks.slack.test/alerts']);

    $failed = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'queue_job_age_exceeded',
        'severity' => 'alert',
        'observed' => 20,
        'threshold' => 15,
    ]);

    expect($failed['success'])->toBeFalse()
        ->and($failed['deliveries']['team_channel'])->toBe('failed');
});

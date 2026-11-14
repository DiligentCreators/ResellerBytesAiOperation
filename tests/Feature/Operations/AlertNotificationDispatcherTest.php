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
        'operations.alerts.slack_webhook_url' => 'https://hooks.slack.test/alerts',
        'operations.alerts.discord_webhook_url' => null,
        'operations.alerts.critical_whatsapp_webhook_url' => null,
        'operations.alerts.critical_sms_webhook_url' => null,
    ]);
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

it('does not report delivery when a required route is missing or a webhook does not acknowledge', function () {
    Mail::fake();
    config([
        'operations.alerts.slack_webhook_url' => null,
        'operations.alerts.discord_webhook_url' => null,
    ]);
    Http::fake();

    $missing = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'queue_job_age_exceeded',
        'severity' => 'alert',
        'observed' => 20,
        'threshold' => 15,
    ]);

    expect($missing['success'])->toBeFalse()
        ->and($missing['deliveries']['team_channel'])->toBe('missing');

    config(['operations.alerts.slack_webhook_url' => 'https://hooks.slack.test/alerts']);
    Http::fake(['https://hooks.slack.test/*' => Http::response([], 503)]);

    $failed = app(AlertNotificationDispatcher::class)->dispatch([
        'code' => 'queue_job_age_exceeded',
        'severity' => 'alert',
        'observed' => 20,
        'threshold' => 15,
    ]);

    expect($failed['success'])->toBeFalse()
        ->and($failed['deliveries']['team_channel'])->toBe('failed');
});

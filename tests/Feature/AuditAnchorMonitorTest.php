<?php

use App\Services\Audit\AuditAnchorService;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $keyPair = sodium_crypto_sign_keypair();
    config([
        'audit.anchor.private_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
        'audit.anchor.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
    ]);
});

function appendAuditAnchorMonitorFixtureEvent(string $eventType): void
{
    app(AuditEventWriter::class)->append([
        'event_type' => $eventType,
        'subject_type' => 'test',
        'subject_id' => 'anchor-monitor',
        'metadata' => ['source' => 'automated-test'],
        'occurred_at' => now(),
    ]);
}

function writeAuditAnchorMonitorFixture(): string
{
    appendAuditAnchorMonitorFixtureEvent('test.anchor.monitor.created');
    $path = tempnam(sys_get_temp_dir(), 'rb-anchor-monitor-');
    file_put_contents($path, app(AuditAnchorService::class)->createCheckpoint());

    return $path;
}

it('reports a fresh verified checkpoint with no unanchored tail as healthy', function () {
    $path = writeAuditAnchorMonitorFixture();

    try {
        expect(Artisan::call('audit:anchor:monitor', ['checkpoint' => $path]))->toBe(0)
            ->and(Artisan::output())->toContain('Audit anchor monitor: healthy.');
    } finally {
        @unlink($path);
    }
});

it('fails when the external checkpoint exceeds the configured age threshold', function () {
    Date::setTestNow(now()->subHours(2));
    $path = writeAuditAnchorMonitorFixture();
    Date::setTestNow(now()->addHours(2));

    try {
        expect(Artisan::call('audit:anchor:monitor', ['checkpoint' => $path, '--max-age-minutes' => 60]))->toBe(1)
            ->and(Artisan::output())->toContain('audit_anchor_checkpoint_stale');
    } finally {
        Date::setTestNow();
        @unlink($path);
    }
});

it('fails when the local audit chain has an unanchored tail above threshold', function () {
    $path = writeAuditAnchorMonitorFixture();
    appendAuditAnchorMonitorFixtureEvent('test.anchor.monitor.after-checkpoint');

    try {
        expect(Artisan::call('audit:anchor:monitor', ['checkpoint' => $path, '--max-unanchored-tail' => 0]))->toBe(1)
            ->and(Artisan::output())->toContain('audit_anchor_unanchored_tail_exceeded');
    } finally {
        @unlink($path);
    }
});

it('fails closed when the checkpoint file is missing', function () {
    expect(Artisan::call('audit:anchor:monitor', ['checkpoint' => sys_get_temp_dir().'/missing-rb-anchor.json']))->toBe(1)
        ->and(Artisan::output())->toContain('checkpoint file is unavailable');
});

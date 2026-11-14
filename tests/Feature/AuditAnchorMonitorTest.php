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
            ->and(Artisan::output())->toContain('checkpoint_stale');
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
            ->and(Artisan::output())->toContain('unanchored_tail_exceeded');
    } finally {
        @unlink($path);
    }
});

it('emits a redacted JSON evidence record for a healthy checkpoint', function () {
    $path = writeAuditAnchorMonitorFixture();

    try {
        expect(Artisan::call('audit:anchor:monitor', ['checkpoint' => $path, '--json' => true]))->toBe(0);
        $evidence = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(base_path('docs/operations/audit-anchor-alert-evidence.schema.json')), true, 512, JSON_THROW_ON_ERROR);

        expect(array_keys($evidence))->toBe($schema['required'])
            ->and($schema['properties']['reason_code']['enum'])->toContain($evidence['reason_code'])
            ->and($evidence)->toMatchArray([
            'schema_version' => 1,
            'status' => 'healthy',
            'reason_code' => 'healthy',
            'exit_code' => 0,
            'unanchored_tail' => 0,
        ])->and($evidence['checkpoint_sha256'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($evidence['observed_at_utc'])->toBeString();
    } finally {
        @unlink($path);
    }
});

it('does not expose raw exception messages or checkpoint contents in JSON evidence', function () {
    $path = tempnam(sys_get_temp_dir(), 'rb-anchor-monitor-secret-');
    file_put_contents($path, '{"secret":"DO_NOT_LEAK_THIS_VALUE","payload":');

    try {
        expect(Artisan::call('audit:anchor:monitor', ['checkpoint' => $path, '--json' => true]))->toBe(1);
        $output = Artisan::output();
        $evidence = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(base_path('docs/operations/audit-anchor-alert-evidence.schema.json')), true, 512, JSON_THROW_ON_ERROR);

        expect(array_keys($evidence))->toBe($schema['required'])
            ->and($schema['properties']['reason_code']['enum'])->toContain($evidence['reason_code'])
            ->and($output)->not->toContain('DO_NOT_LEAK_THIS_VALUE')
            ->and($evidence)->toMatchArray([
                'status' => 'failed',
                'reason_code' => 'verification_failed',
                'exit_code' => 1,
            ]);
    } finally {
        @unlink($path);
    }
});

it('fails closed when the checkpoint file is missing', function () {
    expect(Artisan::call('audit:anchor:monitor', ['checkpoint' => sys_get_temp_dir().'/missing-rb-anchor.json']))->toBe(1)
        ->and(Artisan::output())->toContain('checkpoint_unavailable');
});

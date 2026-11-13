<?php

use App\Services\Audit\AuditEventCanonicalizer;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('canonicalizes associative metadata independent of insertion order while preserving list order', function () {
    $canonicalizer = app(AuditEventCanonicalizer::class);
    $base = [
        'chain_sequence' => 1,
        'previous_hash' => AuditEventCanonicalizer::GENESIS_HASH,
        'actor_type' => 'user',
        'actor_id' => 'operator-1',
        'event_type' => 'test.created',
        'subject_type' => 'test',
        'subject_id' => 'subject-1',
        'metadata' => ['z' => 2, 'nested' => ['b' => 2, 'a' => 1], 'list' => ['first', 'second']],
        'ip_address' => null,
        'occurred_at' => '2026-11-13 10:11:12.123456',
    ];
    $reordered = $base;
    $reordered['metadata'] = ['list' => ['first', 'second'], 'nested' => ['a' => 1, 'b' => 2], 'z' => 2];

    expect($canonicalizer->hash($base))->toBe($canonicalizer->hash($reordered));

    $reordered['metadata']['list'] = ['second', 'first'];
    expect($canonicalizer->hash($base))->not->toBe($canonicalizer->hash($reordered));
});

it('appends events with a contiguous sequence and a valid previous-hash link', function () {
    $writer = app(AuditEventWriter::class);
    $first = $writer->append([
        'actor_type' => 'user',
        'actor_id' => 'operator-1',
        'event_type' => 'test.first',
        'metadata' => ['source' => 'test'],
        'occurred_at' => now(),
    ]);
    $second = $writer->append([
        'actor_type' => 'user',
        'actor_id' => 'operator-1',
        'event_type' => 'test.second',
        'metadata' => ['source' => 'test'],
        'occurred_at' => now(),
    ]);

    expect($first->chain_sequence)->toBe(1)
        ->and($first->previous_hash)->toBe(AuditEventCanonicalizer::GENESIS_HASH)
        ->and($second->chain_sequence)->toBe(2)
        ->and($second->previous_hash)->toBe($first->event_hash);
});

it('verifies a valid chain using the read-only artisan command', function () {
    app(AuditEventWriter::class)->append([
        'event_type' => 'test.verified',
        'metadata' => ['source' => 'test'],
        'occurred_at' => now(),
    ]);

    expect(Artisan::call('audit:verify-integrity'))->toBe(0)
        ->and(Artisan::output())->toContain('Audit integrity verified: 1 event(s)');
});

it('fails verification when the stored chain head has been changed', function () {
    app(AuditEventWriter::class)->append([
        'event_type' => 'test.head-tamper',
        'metadata' => [],
        'occurred_at' => now(),
    ]);
    DB::table('audit_chain_heads')->where('id', 1)->update([
        'head_hash' => str_repeat('f', 64),
    ]);

    expect(Artisan::call('audit:verify-integrity'))->toBe(1)
        ->and(Artisan::output())->toContain('stored chain head does not match');
});

<?php

use App\Services\Audit\AuditEventCanonicalizer;
use App\Services\Audit\AuditEventWriter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('rolls back the inserted audit event when the chain head cannot advance', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION reject_audit_chain_head_update()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            RAISE EXCEPTION 'audit_chain_head_update_rejected';
        END;
        $$;
    SQL);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER audit_chain_head_reject_update
        BEFORE UPDATE ON audit_chain_heads
        FOR EACH ROW EXECUTE FUNCTION reject_audit_chain_head_update();
    SQL);

    try {
        expect(fn () => app(AuditEventWriter::class)->append([
            'event_type' => 'test.rollback',
            'metadata' => ['source' => 'postgres-rollback-test'],
            'occurred_at' => now(),
        ]))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_chain_head_reject_update ON audit_chain_heads');
        DB::unprepared('DROP FUNCTION IF EXISTS reject_audit_chain_head_update()');
    }

    expect(DB::table('audit_events')->count())->toBe(0)
        ->and(DB::table('audit_chain_heads')->where('id', 1)->value('chain_sequence'))->toBe(0);
});

it('backfills pre-chain audit events deterministically in primary-key order', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update_delete ON audit_events');
    DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_truncate ON audit_events');
    Schema::dropIfExists('audit_chain_heads');
    Schema::table('audit_events', function (Blueprint $table): void {
        $table->dropUnique(['chain_sequence']);
        $table->dropColumn(['chain_sequence', 'previous_hash', 'event_hash', 'hash_version']);
    });

    DB::table('audit_events')->insert([
        [
            'actor_type' => 'system_operator',
            'actor_id' => null,
            'event_type' => 'legacy.first',
            'subject_type' => 'legacy',
            'subject_id' => 'one',
            'metadata' => json_encode(['legacy' => 1], JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'occurred_at' => '2026-11-12 10:00:00',
        ],
        [
            'actor_type' => 'system_operator',
            'actor_id' => null,
            'event_type' => 'legacy.second',
            'subject_type' => 'legacy',
            'subject_id' => 'two',
            'metadata' => json_encode(['legacy' => 2], JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'occurred_at' => '2026-11-12 10:01:00',
        ],
    ]);

    $migration = require database_path('migrations/2026_11_13_000003_add_audit_event_hash_chain.php');
    $migration->up();

    $events = DB::table('audit_events')->orderBy('chain_sequence')->get();

    expect($events)->toHaveCount(2)
        ->and((int) $events[0]->chain_sequence)->toBe(1)
        ->and((int) $events[1]->chain_sequence)->toBe(2)
        ->and($events[0]->previous_hash)->toBe(AuditEventCanonicalizer::GENESIS_HASH)
        ->and($events[1]->previous_hash)->toBe($events[0]->event_hash)
        ->and(Artisan::call('audit:verify-integrity'))->toBe(0);
});

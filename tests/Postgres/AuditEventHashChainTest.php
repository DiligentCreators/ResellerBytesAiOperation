<?php

use App\Services\Audit\AuditEventWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

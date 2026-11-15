<?php

use App\Services\Audit\AuditEventWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects PostgreSQL truncate statements against the audit event table', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    app(AuditEventWriter::class)->append([
        'actor_type' => 'user',
        'actor_id' => 'postgres-test-operator',
        'event_type' => 'test.created',
        'subject_type' => 'test',
        'subject_id' => 'postgres-test-subject',
        'metadata' => ['source' => 'postgres-immutability-test'],
        'occurred_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE TABLE audit_events')))
        ->toThrow(QueryException::class);

    expect(DB::table('audit_events')->count())->toBe(1);
});

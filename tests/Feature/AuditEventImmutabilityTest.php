<?php

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAuditEventForImmutabilityTest(): AuditEvent
{
    return AuditEvent::create([
        'actor_type' => 'user',
        'actor_id' => 'test-operator',
        'event_type' => 'test.created',
        'subject_type' => 'test',
        'subject_id' => 'test-subject',
        'metadata' => ['source' => 'automated-test'],
        'occurred_at' => now(),
    ]);
}

it('allows audit events to be appended', function () {
    expect(createAuditEventForImmutabilityTest()->exists)->toBeTrue();
});

it('rejects updates to existing audit events', function () {
    $event = createAuditEventForImmutabilityTest();

    expect(fn () => $event->update(['event_type' => 'test.changed']))
        ->toThrow(LogicException::class, 'Audit events are append-only');
});

it('rejects deletion of existing audit events', function () {
    $event = createAuditEventForImmutabilityTest();

    expect(fn () => $event->delete())
        ->toThrow(LogicException::class, 'Audit events are append-only');
});


it('rejects query-builder updates that bypass Eloquent model events', function () {
    $event = createAuditEventForImmutabilityTest();

    expect(fn () => DB::table('audit_events')
        ->where('id', $event->id)
        ->update(['event_type' => 'tampered']))->toThrow(QueryException::class);
});

it('rejects query-builder deletes that bypass Eloquent model events', function () {
    $event = createAuditEventForImmutabilityTest();

    expect(fn () => DB::table('audit_events')
        ->where('id', $event->id)
        ->delete())->toThrow(QueryException::class);
});

it('rejects truncate statements on PostgreSQL', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->skip('TRUNCATE protection is PostgreSQL-specific; other drivers use their own privilege controls.');
    }

    createAuditEventForImmutabilityTest();

    expect(fn () => DB::statement('TRUNCATE TABLE audit_events'))
        ->toThrow(QueryException::class);
});

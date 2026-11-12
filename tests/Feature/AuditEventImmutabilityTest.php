<?php

use App\\Models\\AuditEvent;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;

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

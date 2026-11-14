<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_type',
        'actor_id',
        'event_type',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
        'occurred_at',
        'chain_sequence',
        'previous_hash',
        'event_hash',
        'hash_version',
    ];

    protected static function booted(): void
    {
        static::creating(function (): never {
            throw new LogicException('Audit events must be appended through AuditEventWriter.');
        });

        static::updating(function (): never {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'chain_sequence' => 'integer',
            'hash_version' => 'integer',
        ];
    }
}

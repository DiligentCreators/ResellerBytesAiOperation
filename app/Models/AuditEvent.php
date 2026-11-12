<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
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
    ];

    protected static function booted(): void
    {
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
        ];
    }
}

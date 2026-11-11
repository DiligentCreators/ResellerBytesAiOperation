<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasFactory;

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

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}

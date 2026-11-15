<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalHold extends Model
{
    use HasUuids;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'status',
        'placed_by_user_id',
        'released_by_user_id',
        'reason',
        'evidence_reference',
        'placed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }
}

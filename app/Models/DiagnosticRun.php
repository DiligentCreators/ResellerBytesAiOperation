<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'integration_id',
        'snapshot_uuid',
        'schema_version',
        'addon_version',
        'status',
        'collected_at',
        'received_at',
        'payload',
        'payload_sha256',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WhmcsIntegration::class, 'integration_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(DiagnosticFinding::class);
    }
}

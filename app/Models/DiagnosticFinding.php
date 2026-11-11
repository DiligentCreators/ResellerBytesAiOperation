<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticFinding extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'diagnostic_run_id',
        'fingerprint',
        'category',
        'severity',
        'title',
        'summary',
        'evidence',
        'confidence',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'decimal:4',
        ];
    }

    public function diagnosticRun(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(DiagnosticRecommendation::class);
    }
}

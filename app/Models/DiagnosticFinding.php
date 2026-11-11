<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticFinding extends Model
{
    use HasUuids;

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

    /**
     * @return BelongsTo<DiagnosticRun, $this>
     */
    public function diagnosticRun(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class);
    }

    /**
     * @return HasMany<DiagnosticRecommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(DiagnosticRecommendation::class);
    }
}

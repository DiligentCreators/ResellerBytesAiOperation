<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $diagnostic_run_id
 * @property string $fingerprint
 * @property string $category
 * @property string $severity
 * @property string $title
 * @property string $summary
 * @property array<string, mixed> $evidence
 * @property string|null $confidence
 * @property string $status
 * @property-read DiagnosticRun $diagnosticRun
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DiagnosticRecommendation> $recommendations
 */
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

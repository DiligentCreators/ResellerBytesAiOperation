<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosticRecommendation extends Model
{
    use HasUuids;

    protected $fillable = [
        'diagnostic_finding_id',
        'recommendation',
        'rationale',
        'risk_level',
        'requires_manual_action',
    ];

    protected function casts(): array
    {
        return [
            'requires_manual_action' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<DiagnosticFinding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(DiagnosticFinding::class, 'diagnostic_finding_id');
    }
}

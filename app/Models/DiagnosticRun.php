<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticRun extends Model
{
    use HasUuids;

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
        'analysis_model',
        'analysis_prompt_version',
        'analysis_response_id',
        'analysis_summary',
        'no_findings_reason',
        'analysis_started_at',
        'analysis_completed_at',
        'analysis_error_code',
        'analysis_attempts',
        'analysis_usage',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'payload' => 'array',
            'analysis_started_at' => 'immutable_datetime',
            'analysis_completed_at' => 'immutable_datetime',
            'analysis_usage' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WhmcsIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(WhmcsIntegration::class, 'integration_id');
    }

    /**
     * @return HasMany<DiagnosticFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(DiagnosticFinding::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $integration_id
 * @property string $snapshot_uuid
 * @property string $schema_version
 * @property string $addon_version
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $collected_at
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property array<string, mixed> $payload
 * @property string $payload_sha256
 * @property string|null $analysis_summary
 * @property string|null $no_findings_reason
 * @property \Illuminate\Support\Carbon|null $analysis_started_at
 * @property \Illuminate\Support\Carbon|null $analysis_completed_at
 * @property string|null $analysis_error_code
 * @property int $analysis_attempts
 * @property array<string, int>|null $analysis_usage
 * @property-read WhmcsIntegration $integration
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DiagnosticFinding> $findings
 * @property-read int $findings_count
 */
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

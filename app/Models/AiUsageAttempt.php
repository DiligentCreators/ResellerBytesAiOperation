<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AiUsageAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'diagnostic_run_id',
        'tenant_id',
        'attempt_number',
        'model',
        'prompt_version',
        'price_card_version',
        'input_tenths_micro_usd_per_token',
        'cached_input_tenths_micro_usd_per_token',
        'output_tenths_micro_usd_per_token',
        'daily_period_start',
        'monthly_period_start',
        'status',
        'reserved_micro_usd',
        'settled_micro_usd',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'total_tokens',
        'provider_response_id',
        'failure_code',
        'submitted_at',
        'settled_at',
        'reconciled_at',
        'reconciled_by',
        'reconciliation_approved_by',
        'reconciliation_approved_at',
        'reconciliation_note',
    ];

    protected static function booted(): void
    {
        static::updating(function (AiUsageAttempt $attempt): void {
            if ($attempt->isDirty('tenant_id')) {
                throw new LogicException('AI usage attempt tenant attribution is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'reconciliation_approved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DiagnosticRun, $this> */
    public function diagnosticRun(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

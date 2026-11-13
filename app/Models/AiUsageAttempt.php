<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'diagnostic_run_id',
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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property \Illuminate\Support\Carbon $period_start */
class AiTenantBudgetPeriod extends Model
{
    protected $fillable = [
        'tenant_id',
        'period_type',
        'period_start',
        'limit_micro_usd',
        'reserved_micro_usd',
        'committed_micro_usd',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'date'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

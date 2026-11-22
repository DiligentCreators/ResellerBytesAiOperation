<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $safe_action_execution_id
 * @property string $status
 * @property array<string, mixed> $payload_summary
 * @property Carbon $received_at
 * @property-read Tenant $tenant
 * @property-read SafeActionExecution $execution
 */
class SafeActionLateResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'safe_action_execution_id',
        'status',
        'payload_summary',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<SafeActionExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(SafeActionExecution::class, 'safe_action_execution_id');
    }
}

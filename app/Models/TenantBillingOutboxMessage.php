<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**\n * @property array<string, mixed> $payload\n * @property \\Illuminate\\Support\\Carbon $available_at\n * @property \\Illuminate\\Support\\Carbon|null $locked_at\n * @property \\Illuminate\\Support\\Carbon|null $delivered_at\n * @property \\Illuminate\\Support\\Carbon|null $failed_at\n */\nclass TenantBillingOutboxMessage extends Model
{
    protected $table = 'tenant_billing_outbox';

    protected $fillable = [
        'tenant_id', 'idempotency_key', 'event_type', 'aggregate_type', 'aggregate_id',
        'payload', 'status', 'attempts', 'manual_replay_count', 'available_at', 'locked_at', 'delivered_at',
        'failed_at', 'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'manual_replay_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**\n * @property array<string, mixed> $payload\n * @property \Carbon\CarbonImmutable $received_at\n * @property \Carbon\CarbonImmutable|null $provider_occurred_at\n * @property \Carbon\CarbonImmutable|null $available_at\n * @property \Carbon\CarbonImmutable|null $locked_at\n * @property \Carbon\CarbonImmutable|null $processed_at\n * @property \Carbon\CarbonImmutable|null $dead_lettered_at\n */\nclass TenantBillingWebhookEvent extends Model
{
    protected $fillable = [
        'tenant_id', 'provider', 'provider_account_id', 'provider_event_id', 'event_type',
        'payload_sha256', 'payload', 'provider_occurred_at', 'status', 'attempts', 'manual_replay_count', 'received_at', 'available_at',
        'locked_at', 'processed_at', 'dead_lettered_at', 'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'manual_replay_count' => 'integer',
            'received_at' => 'immutable_datetime',
            'provider_occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'dead_lettered_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

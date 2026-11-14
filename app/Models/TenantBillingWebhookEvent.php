<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class TenantBillingWebhookEvent extends Model
{
    protected $fillable = [
        'tenant_id', 'provider', 'provider_account_id', 'provider_event_id', 'event_type',
        'payload_sha256', 'payload', 'status', 'attempts', 'received_at', 'available_at',
        'locked_at', 'processed_at', 'dead_lettered_at', 'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
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

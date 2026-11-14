<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class TenantBillingAdjustment extends Model
{
    protected $fillable = [
        'tenant_id', 'tenant_billing_payment_id', 'provider', 'provider_account_id', 'provider_adjustment_id',
        'kind', 'idempotency_key', 'amount_minor', 'currency', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<TenantBillingPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(TenantBillingPayment::class, 'tenant_billing_payment_id');
    }
}

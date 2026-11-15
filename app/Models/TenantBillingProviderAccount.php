<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property \Carbon\CarbonImmutable|null $verified_at */
class TenantBillingProviderAccount extends Model
{
    protected $fillable = [
        'tenant_id', 'provider', 'provider_account_id', 'status', 'verified_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $entitlements
 * @property CarbonImmutable $current_period_starts_at
 * @property CarbonImmutable $current_period_ends_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property CarbonImmutable|null $canceled_at
 */
class TenantSubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'plan_code',
        'billing_mode',
        'status',
        'billing_provider',
        'provider_account_id',
        'provider_subscription_id',
        'currency',
        'unit_amount_minor',
        'entitlements',
        'cancel_at_period_end',
        'current_period_starts_at',
        'current_period_ends_at',
        'grace_ends_at',
        'canceled_at',
        'replaces_subscription_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'unit_amount_minor' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'current_period_starts_at' => 'immutable_datetime',
            'current_period_ends_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<TenantBillingInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(TenantBillingInvoice::class);
    }
}

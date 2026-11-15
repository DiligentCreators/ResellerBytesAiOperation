<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**\n * @property array<string, mixed> $entitlements\n * @property \Carbon\CarbonImmutable|null $current_period_starts_at\n * @property \Carbon\CarbonImmutable|null $current_period_ends_at\n * @property \Carbon\CarbonImmutable|null $grace_ends_at\n * @property \Carbon\CarbonImmutable|null $canceled_at\n */\nclass TenantSubscription extends Model
{
    protected $fillable = ['tenant_id', 'plan_code', 'billing_mode', 'status', 'billing_provider', 'provider_account_id', 'provider_subscription_id', 'currency', 'unit_amount_minor', 'entitlements', 'current_period_starts_at', 'current_period_ends_at', 'grace_ends_at', 'canceled_at', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['entitlements' => 'array', 'unit_amount_minor' => 'integer', 'current_period_starts_at' => 'immutable_datetime', 'current_period_ends_at' => 'immutable_datetime', 'grace_ends_at' => 'immutable_datetime', 'canceled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    /** @return HasMany<TenantBillingInvoice, $this> */
    public function invoices(): HasMany { return $this->hasMany(TenantBillingInvoice::class); }
}

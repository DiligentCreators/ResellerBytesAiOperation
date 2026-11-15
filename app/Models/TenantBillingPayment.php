<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantBillingPayment extends Model
{
    protected $fillable = ['tenant_id', 'tenant_billing_invoice_id', 'collection_mode', 'idempotency_key', 'provider', 'provider_account_id', 'provider_payment_id', 'status', 'currency', 'amount_minor', 'reference', 'recorded_by_user_id', 'received_at'];

    protected function casts(): array { return ['amount_minor' => 'integer', 'received_at' => 'immutable_datetime']; }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    /** @return BelongsTo<TenantBillingInvoice, $this> */
    public function invoice(): BelongsTo { return $this->belongsTo(TenantBillingInvoice::class, 'tenant_billing_invoice_id'); }

    /** @return HasMany<TenantBillingAdjustment, $this> */
    public function adjustments(): HasMany { return $this->hasMany(TenantBillingAdjustment::class, 'tenant_billing_payment_id'); }
}

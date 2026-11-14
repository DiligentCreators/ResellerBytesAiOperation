<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantBillingInvoice extends Model
{
    protected $fillable = ['tenant_id', 'tenant_subscription_id', 'invoice_number', 'status', 'currency', 'amount_due_minor', 'amount_paid_minor', 'issued_at', 'due_at', 'paid_at'];

    protected function casts(): array { return ['amount_due_minor' => 'integer', 'amount_paid_minor' => 'integer', 'issued_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime']; }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    /** @return BelongsTo<TenantSubscription, $this> */
    public function subscription(): BelongsTo { return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id'); }

    /** @return HasMany<TenantBillingPayment, $this> */
    public function payments(): HasMany { return $this->hasMany(TenantBillingPayment::class, 'tenant_billing_invoice_id'); }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBillingPayment extends Model
{
    protected $fillable = ['tenant_id', 'tenant_billing_invoice_id', 'collection_mode', 'provider', 'provider_payment_id', 'status', 'currency', 'amount_minor', 'reference', 'recorded_by_user_id', 'received_at'];

    protected function casts(): array { return ['amount_minor' => 'integer', 'received_at' => 'immutable_datetime']; }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    /** @return BelongsTo<TenantBillingInvoice, $this> */
    public function invoice(): BelongsTo { return $this->belongsTo(TenantBillingInvoice::class, 'tenant_billing_invoice_id'); }
}

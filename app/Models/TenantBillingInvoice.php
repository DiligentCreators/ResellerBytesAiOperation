<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $amount_due_minor
 * @property int $amount_paid_minor
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property array<int, array<string, mixed>>|null $line_items
 * @property array<string, mixed>|null $billing_details
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $paid_at
 */
class TenantBillingInvoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'invoice_number',
        'provider',
        'provider_account_id',
        'provider_invoice_id',
        'provider_remote_invoice_id',
        'provider_remote_status',
        'payment_link',
        'status',
        'currency',
        'subtotal_minor',
        'tax_minor',
        'amount_due_minor',
        'amount_paid_minor',
        'line_items',
        'billing_details',
        'issued_at',
        'due_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'amount_due_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'line_items' => 'array',
            'billing_details' => 'array',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<TenantSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    /** @return HasMany<TenantBillingPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(TenantBillingPayment::class, 'tenant_billing_invoice_id');
    }
}

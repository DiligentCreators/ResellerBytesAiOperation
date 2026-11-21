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
 * @property CarbonImmutable|null $service_period_starts_at
 * @property CarbonImmutable|null $service_period_ends_at
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
        'service_period_starts_at',
        'service_period_ends_at',
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
            'service_period_starts_at' => 'immutable_datetime',
            'service_period_ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * Zero-amount card-hold document created for trial checkout session tracking.
     * Must not be presented as a normal paid invoice.
     */
    public function isZeroAmountTrialDocument(): bool
    {
        if ((int) $this->amount_due_minor !== 0) {
            return false;
        }

        $details = is_array($this->billing_details) ? $this->billing_details : [];

        return ($details['checkout_kind'] ?? null) === 'trial';
    }

    /**
     * Status string for tenant/platform invoice lists (never "paid" for trial holds).
     */
    public function listStatus(): string
    {
        if ($this->isZeroAmountTrialDocument()) {
            return 'trial_started_no_charge';
        }

        return $this->status;
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

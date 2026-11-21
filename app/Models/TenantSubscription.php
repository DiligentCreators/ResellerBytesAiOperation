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
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $scheduled_change_at
 * @property int|null $scheduled_plan_id
 * @property string|null $charge_period
 * @property string|null $scheduled_charge_period
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
        'charge_period',
        'entitlements',
        'cancel_at_period_end',
        'current_period_starts_at',
        'current_period_ends_at',
        'trial_ends_at',
        'grace_ends_at',
        'canceled_at',
        'replaces_subscription_id',
        'scheduled_plan_id',
        'scheduled_charge_period',
        'scheduled_change_at',
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
            'trial_ends_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'scheduled_change_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Plan, $this> */
    public function scheduledPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'scheduled_plan_id');
    }

    /** @return HasMany<TenantBillingInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(TenantBillingInvoice::class);
    }

    /**
     * TrialService-created trial that may convert via paid CheckoutService::begin.
     * Paid checkout never sets trial_ends_at; any amount_paid_minor > 0 means not unpaid.
     */
    public function isConvertibleTrial(): bool
    {
        if ($this->status !== 'trialing' || $this->trial_ends_at === null) {
            return false;
        }

        return ! $this->invoices()
            ->where('amount_paid_minor', '>', 0)
            ->exists();
    }

    /**
     * Whole days remaining until trial_ends_at (UTC), or null when not a trial.
     */
    public function trialDaysRemaining(?CarbonImmutable $now = null): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        $now ??= CarbonImmutable::now('UTC');

        return max(0, (int) $now->diffInDays($this->trial_ends_at, false));
    }
}

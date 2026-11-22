<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $entitlements
 * @property list<string>|null $features
 * @property int|null $price_monthly_minor
 * @property int|null $price_yearly_minor
 * @property int $trial_days Trial length in days when trial_enabled; paid checkout never applies this.
 * @property bool $trial_enabled
 * @property bool $monthly_active
 * @property bool $yearly_active
 * @property Carbon|null $prices_confirmed_at
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'price_monthly_minor',
        'price_yearly_minor',
        'currency',
        'trial_days',
        'trial_enabled',
        'features',
        'entitlements',
        'sort_order',
        'is_active',
        'is_public',
        'is_contact_sales',
        'monthly_active',
        'yearly_active',
        'prices_confirmed_at',
        'kelviq_plan_identifier_monthly',
        'kelviq_plan_identifier_yearly',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'trial_enabled' => false,
        'monthly_active' => true,
        'yearly_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'features' => 'array',
            'price_monthly_minor' => 'integer',
            'price_yearly_minor' => 'integer',
            'trial_days' => 'integer',
            'trial_enabled' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_contact_sales' => 'boolean',
            'monthly_active' => 'boolean',
            'yearly_active' => 'boolean',
            'prices_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<TenantSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function priceForPeriod(string $period): int
    {
        $amount = $period === 'yearly' ? $this->price_yearly_minor : $this->price_monthly_minor;

        if ($amount === null) {
            throw new \RuntimeException('billing_plan_price_missing');
        }

        return $amount;
    }

    public function intervalIsActive(string $period): bool
    {
        return $period === 'yearly' ? (bool) $this->yearly_active : (bool) $this->monthly_active;
    }

    /**
     * True when both catalog price columns are set (admin has entered amounts).
     */
    public function hasPrices(): bool
    {
        return $this->price_monthly_minor !== null && $this->price_yearly_minor !== null;
    }

    public function isCheckoutable(): bool
    {
        return $this->is_active && ! $this->is_contact_sales && $this->hasPrices();
    }

    public function kelviqPlanIdentifierForPeriod(string $period): ?string
    {
        $identifier = $period === 'yearly'
            ? $this->kelviq_plan_identifier_yearly
            : $this->kelviq_plan_identifier_monthly;

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    /**
     * True while any public active purchasable plan has never had prices confirmed in admin.
     */
    public static function catalogHasUnconfirmedPrices(): bool
    {
        return static::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->where('is_contact_sales', false)
            ->whereNull('prices_confirmed_at')
            ->exists();
    }

    public function isReferencedByBilling(): bool
    {
        if ($this->subscriptions()->exists()) {
            return true;
        }

        return TenantSubscription::query()
            ->where('scheduled_plan_id', $this->id)
            ->exists();
    }

    /**
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePublicCatalog(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('is_public', true)
            ->where(function (Builder $inner): void {
                $inner->where('is_contact_sales', true)
                    ->orWhere(function (Builder $priced): void {
                        $priced->whereNotNull('price_monthly_minor')
                            ->whereNotNull('price_yearly_minor');
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}

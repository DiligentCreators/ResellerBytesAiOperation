<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $entitlements
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
        'entitlements',
        'sort_order',
        'is_active',
        'is_public',
        'is_contact_sales',
        'kelviq_plan_identifier_monthly',
        'kelviq_plan_identifier_yearly',
    ];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'price_monthly_minor' => 'integer',
            'price_yearly_minor' => 'integer',
            'trial_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_contact_sales' => 'boolean',
        ];
    }

    /** @return HasMany<TenantSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function priceForPeriod(string $period): int
    {
        return $period === 'yearly' ? $this->price_yearly_minor : $this->price_monthly_minor;
    }

    public function kelviqPlanIdentifierForPeriod(string $period): ?string
    {
        $identifier = $period === 'yearly'
            ? $this->kelviq_plan_identifier_yearly
            : $this->kelviq_plan_identifier_monthly;

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    /**
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePublicCatalog(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->orderBy('id');
    }
}

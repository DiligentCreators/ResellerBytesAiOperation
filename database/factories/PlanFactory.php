<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monthly = fake()->numberBetween(1000, 50_000);

        return [
            'code' => 'plan-'.fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price_monthly_minor' => $monthly,
            'price_yearly_minor' => $monthly * fake()->numberBetween(8, 12),
            'currency' => 'USD',
            'trial_days' => 14,
            'trial_enabled' => false,
            'features' => null,
            'monthly_active' => true,
            'yearly_active' => true,
            'prices_confirmed_at' => null,
            'entitlements' => [
                'diagnostics.analysis' => true,
                'diagnostics.reanalyze' => true,
                'safe_actions.enabled' => true,
                'limits' => [
                    'members' => 5,
                    'integrations' => 1,
                    'diagnostics_per_period' => 50,
                    'retention_days' => 30,
                ],
                'support_level' => 'email',
            ],
            'sort_order' => 10,
            'is_active' => true,
            'is_public' => true,
            'is_contact_sales' => false,
            'kelviq_plan_identifier_monthly' => null,
            'kelviq_plan_identifier_yearly' => null,
        ];
    }
}

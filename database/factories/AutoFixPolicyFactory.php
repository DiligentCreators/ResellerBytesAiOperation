<?php

namespace Database\Factories;

use App\Models\AutoFixPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoFixPolicy>
 */
class AutoFixPolicyFactory extends Factory
{
    protected $model = AutoFixPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integration_id' => null,
            'action_key' => 'invoice_send_overdue_reminder',
            'enabled' => true,
            'daily_cap' => null,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}

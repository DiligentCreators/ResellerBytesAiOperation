<?php

namespace Database\Factories;

use App\Models\WhmcsHealthSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhmcsHealthSnapshot>
 */
class WhmcsHealthSnapshotFactory extends Factory
{
    protected $model = WhmcsHealthSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $capturedAt = now('UTC');

        return [
            'id' => (string) Str::uuid(),
            'captured_at' => $capturedAt,
            'captured_on' => $capturedAt->toDateString(),
            'score' => fake()->numberBetween(0, 100),
            'critical_count' => 0,
            'warning_count' => 0,
            'info_count' => 0,
            'metrics' => [
                'module_queue_pending' => null,
                'overdue_unpaid_count' => null,
                'suspended_with_paid' => null,
                'sla_breaches' => null,
                'domains_failing' => null,
                'heartbeat_state' => 'unknown',
                'disk_free_percent' => null,
            ],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\TenantReportSend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantReportSend>
 */
class TenantReportSendFactory extends Factory
{
    protected $model = TenantReportSend::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now('UTC')->subWeek()->startOfDay();

        return [
            'type' => TenantReportSend::TYPE_WEEKLY,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->addDays(6)->toDateString(),
            'sent_at' => now('UTC'),
            'summary' => [
                'tenant_name' => 'Example Workspace',
                'health_score' => 80,
                'issues_fixed' => 0,
            ],
        ];
    }

    public function weekly(): static
    {
        return $this->state(fn (): array => [
            'type' => TenantReportSend::TYPE_WEEKLY,
        ]);
    }

    public function monthlyValue(): static
    {
        return $this->state(fn (): array => [
            'type' => TenantReportSend::TYPE_MONTHLY_VALUE,
        ]);
    }
}

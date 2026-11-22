<?php

namespace App\Services\Health;

use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Services\PlatformSettings;
use Carbon\CarbonInterface;

class TimeSavedCalculator
{
    /** @var list<string> */
    private const COUNTED_MODES = [
        SafeActionExecution::MODE_ONE_CLICK,
        SafeActionExecution::MODE_BULK,
        SafeActionExecution::MODE_AUTO,
        SafeActionExecution::MODE_GUIDED,
    ];

    public function __construct(
        private readonly PlatformSettings $platformSettings,
    ) {}

    /**
     * @return array{
     *     minutes: int,
     *     hours: float,
     *     hours_display: string,
     *     hourly_rate_usd: int,
     *     value_usd: float,
     *     value_display: string
     * }
     */
    public function forTenantMonth(Tenant $tenant, ?CarbonInterface $month = null): array
    {
        $month = ($month ?? now('UTC'))->copy()->utc();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $minutes = (int) SafeActionExecution::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', SafeActionExecution::STATUS_VERIFIED)
            ->whereIn('mode', self::COUNTED_MODES)
            ->whereNotNull('minutes_saved')
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('verified_at', [$start, $end])
                    ->orWhere(function ($fallback) use ($start, $end): void {
                        $fallback->whereNull('verified_at')
                            ->whereBetween('executed_at', [$start, $end]);
                    });
            })
            ->sum('minutes_saved');

        $hours = round($minutes / 60, 1);
        $rate = $this->hourlyRateUsd($tenant);
        $value = round($hours * $rate, 2);

        return [
            'minutes' => $minutes,
            'hours' => $hours,
            'hours_display' => number_format($hours, 1, '.', ''),
            'hourly_rate_usd' => $rate,
            'value_usd' => $value,
            'value_display' => number_format($value, 2, '.', ''),
        ];
    }

    public function hourlyRateUsd(Tenant $tenant): int
    {
        if ($tenant->hourly_rate_usd !== null) {
            return max(0, (int) $tenant->hourly_rate_usd);
        }

        $platform = $this->platformSettings->get('reports.default_hourly_rate_usd');

        if (is_numeric($platform)) {
            return max(0, (int) $platform);
        }

        return max(0, (int) config('reports.default_hourly_rate_usd', 25));
    }
}

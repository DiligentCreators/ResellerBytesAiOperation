<?php

namespace App\Services\Health;

use App\Models\Tenant;
use App\Models\WhmcsHealthSnapshot;
use App\Services\Tenancy\TenantEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;

class HealthHistoryService
{
    public function __construct(
        private readonly TenantEntitlementService $entitlements,
    ) {}

    /**
     * Resolve the maximum history window in days for a tenant.
     */
    public function historyDays(Tenant $tenant): int
    {
        $default = (int) config('health.history_days.default', 90);

        try {
            $subscription = $this->entitlements->activeSubscription($tenant->id);
        } catch (AuthorizationException) {
            return $default;
        }

        $entitlements = (array) $subscription->entitlements;
        $limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
        if (isset($limits['history_days']) && is_numeric($limits['history_days'])) {
            return max(1, (int) $limits['history_days']);
        }

        $subscription->loadMissing('plan');
        $plan = $subscription->plan;
        $planEntitlements = $plan !== null ? (array) $plan->entitlements : [];
        $planLimits = is_array($planEntitlements['limits'] ?? null) ? $planEntitlements['limits'] : [];
        if (isset($planLimits['history_days']) && is_numeric($planLimits['history_days'])) {
            return max(1, (int) $planLimits['history_days']);
        }

        $planCode = (string) ($subscription->plan_code ?: ($plan !== null ? $plan->code : ''));
        $byPlan = (array) config('health.history_days.by_plan', []);
        if ($planCode !== '' && isset($byPlan[$planCode]) && is_numeric($byPlan[$planCode])) {
            return max(1, (int) $byPlan[$planCode]);
        }

        return $default;
    }

    /**
     * Clamp a requested window to the tenant's entitlement.
     */
    public function clampRangeDays(Tenant $tenant, int $requested): int
    {
        $allowed = [30, 90, 365];
        if (! in_array($requested, $allowed, true)) {
            $requested = 30;
        }

        return min($requested, $this->historyDays($tenant));
    }

    /**
     * @return array{
     *     range_days: int,
     *     max_history_days: int,
     *     points: list<array{
     *         date: string,
     *         score: int,
     *         critical_count: int,
     *         warning_count: int,
     *         info_count: int,
     *         metrics: array<string, mixed>
     *     }>
     * }
     */
    public function trendSeries(Tenant $tenant, int $requestedDays): array
    {
        $rangeDays = $this->clampRangeDays($tenant, $requestedDays);
        $from = now('UTC')->subDays($rangeDays - 1)->startOfDay();

        $rows = WhmcsHealthSnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('captured_on', '>=', $from->toDateString())
            ->orderBy('captured_on')
            ->get();

        /** @var array<string, array{score_sum: int, count: int, critical: int, warning: int, info: int, metrics: array<string, mixed>}> $byDay */
        $byDay = [];
        foreach ($rows as $row) {
            $day = $row->captured_on?->toDateString();
            if ($day === null) {
                continue;
            }
            $byDay[$day] ??= [
                'score_sum' => 0,
                'count' => 0,
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
                'metrics' => is_array($row->metrics) ? $row->metrics : [],
            ];
            $byDay[$day]['score_sum'] += (int) $row->score;
            $byDay[$day]['count']++;
            $byDay[$day]['critical'] += (int) $row->critical_count;
            $byDay[$day]['warning'] += (int) $row->warning_count;
            $byDay[$day]['info'] += (int) $row->info_count;
            // Prefer the latest metrics blob of the day for chart series.
            if (is_array($row->metrics)) {
                $byDay[$day]['metrics'] = $row->metrics;
            }
        }

        $points = [];
        foreach ($byDay as $day => $aggregate) {
            $points[] = [
                'date' => $day,
                'score' => (int) round($aggregate['score_sum'] / max(1, $aggregate['count'])),
                'critical_count' => $aggregate['critical'],
                'warning_count' => $aggregate['warning'],
                'info_count' => $aggregate['info'],
                'metrics' => $aggregate['metrics'],
            ];
        }

        return [
            'range_days' => $rangeDays,
            'max_history_days' => $this->historyDays($tenant),
            'points' => $points,
        ];
    }
}

<?php

namespace App\Services\Health;

use App\Models\DiagnosticRun;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhmcsHealthSnapshot;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use App\Services\Tenancy\TenantRecordQuery;

class HealthDashboardPresenter
{
    public function __construct(
        private readonly TenantRecordQuery $tenantRecords,
        private readonly TimeSavedCalculator $timeSaved,
    ) {}

    /**
     * @return array{
     *     score: int|null,
     *     change_vs_last_week: int|null,
     *     sparkline: list<array{date: string, score: int}>,
     *     last_scan_clean: bool
     * }
     */
    public function healthCard(?User $user, bool $lastScanClean): array
    {
        $tenantIds = $this->tenantIds($user);
        if ($tenantIds === []) {
            return [
                'score' => null,
                'change_vs_last_week' => null,
                'sparkline' => [],
                'last_scan_clean' => $lastScanClean,
            ];
        }

        $from = now('UTC')->subDays(29)->startOfDay();
        $rows = WhmcsHealthSnapshot::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereDate('captured_on', '>=', $from->toDateString())
            ->orderBy('captured_on')
            ->get(['captured_on', 'score']);

        $byDay = [];
        foreach ($rows as $row) {
            $day = $row->captured_on?->toDateString();
            if ($day === null) {
                continue;
            }
            // Workspace roll-up: average across installations for the day.
            $byDay[$day] ??= ['sum' => 0, 'count' => 0];
            $byDay[$day]['sum'] += (int) $row->score;
            $byDay[$day]['count']++;
        }

        $sparkline = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = now('UTC')->subDays($i)->toDateString();
            if (! isset($byDay[$day])) {
                continue;
            }
            $sparkline[] = [
                'date' => $day,
                'score' => (int) round($byDay[$day]['sum'] / max(1, $byDay[$day]['count'])),
            ];
        }

        $latest = $sparkline === [] ? null : $sparkline[array_key_last($sparkline)]['score'];
        $weekAgoDay = now('UTC')->subDays(7)->toDateString();
        $weekAgo = null;
        foreach ($sparkline as $point) {
            if ($point['date'] <= $weekAgoDay) {
                $weekAgo = $point['score'];
            }
        }

        return [
            'score' => $latest,
            'change_vs_last_week' => ($latest !== null && $weekAgo !== null) ? ($latest - $weekAgo) : null,
            'sparkline' => $sparkline,
            'last_scan_clean' => $lastScanClean,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     severity: string,
     *     issue_key: string|null,
     *     fix_mode: string|null,
     *     priority: int,
     *     installation: string|null,
     *     primary_action: 'fix_now'|'start_guide'|'review'
     * }>
     */
    public function needsYouNow(?User $user, int $limit = 3): array
    {
        $findings = $this->tenantRecords->findings($user)
            ->with(['diagnosticRun.integration:id,name'])
            ->currentIssues()
            ->active()
            ->notIgnored()
            ->get();

        /** @var list<array{id: string, title: string, severity: string, issue_key: string|null, fix_mode: string|null, priority: int, installation: string|null, primary_action: 'fix_now'|'start_guide'|'review'}> $items */
        $items = [];
        foreach ($findings as $finding) {
            $issueKey = $finding->issue_key;
            $priority = is_string($issueKey) && $issueKey !== ''
                ? WhmcsIssueCatalog::getInt($issueKey, 'priority', 999)
                : 999;
            $fixMode = $finding->fix_mode
                ?? (is_string($issueKey) ? WhmcsIssueCatalog::getString($issueKey, 'fix_mode') : null);
            $primary = match ($fixMode) {
                'one_click', 'bulk' => 'fix_now',
                'guided' => 'start_guide',
                default => 'review',
            };

            $items[] = [
                'id' => $finding->id,
                'title' => is_string($issueKey) && $issueKey !== ''
                    ? (WhmcsIssueCatalog::getString($issueKey, 'title') ?? $finding->title)
                    : $finding->title,
                'severity' => $finding->severity,
                'issue_key' => $issueKey,
                'fix_mode' => $fixMode,
                'priority' => $priority,
                'installation' => $finding->diagnosticRun?->integration?->name,
                'primary_action' => $primary,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return [$left['priority'], $left['severity']] <=> [$right['priority'], $right['severity']];
        });

        return array_values(array_slice($items, 0, $limit));
    }

    /**
     * @return array{
     *     hours: float,
     *     hours_display: string,
     *     value_usd: float,
     *     value_display: string,
     *     hourly_rate_usd: int
     * }|null
     */
    public function timeSavedThisMonth(?User $user): ?array
    {
        $tenant = $this->primaryTenant($user);
        if ($tenant === null) {
            return null;
        }

        $result = $this->timeSaved->forTenantMonth($tenant);

        return [
            'hours' => $result['hours'],
            'hours_display' => $result['hours_display'],
            'value_usd' => $result['value_usd'],
            'value_display' => $result['value_display'],
            'hourly_rate_usd' => $result['hourly_rate_usd'],
        ];
    }

    /**
     * Sum of recovered_paid_7d_total from the latest snapshot per installation this month.
     *
     * @return array{amount: float, display: string}|null
     */
    public function recoveredThisMonth(?User $user): ?array
    {
        $tenantIds = $this->tenantIds($user);
        if ($tenantIds === []) {
            return null;
        }

        $start = now('UTC')->startOfMonth();
        $runs = DiagnosticRun::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('received_at', '>=', $start)
            ->orderByDesc('received_at')
            ->get(['id', 'integration_id', 'payload', 'received_at']);

        $latestPerIntegration = [];
        foreach ($runs as $run) {
            if (isset($latestPerIntegration[$run->integration_id])) {
                continue;
            }
            $latestPerIntegration[$run->integration_id] = $run;
        }

        $total = 0.0;
        $hasData = false;
        foreach ($latestPerIntegration as $run) {
            $raw = data_get($run->payload, 'whmcs.billing.recovered_paid_7d_total');
            if (! is_numeric($raw) && ! (is_string($raw) && is_numeric($raw))) {
                continue;
            }
            $hasData = true;
            $total += (float) $raw;
        }

        if (! $hasData || $total <= 0) {
            return null;
        }

        return [
            'amount' => round($total, 2),
            'display' => number_format($total, 2, '.', ''),
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     status: string,
     *     heartbeat_state: string,
     *     last_heartbeat_at: string|null,
     *     last_seen_at: string|null,
     *     runs_count: int,
     *     open_findings: int,
     *     addon_version: string|null
     * }>
     */
    public function installationsStatus(?User $user): array
    {
        $integrations = $this->tenantRecords->integrations($user)
            ->withCount(['diagnosticRuns as diagnostic_runs_count'])
            ->orderBy('name')
            ->limit(12)
            ->get();

        $items = [];
        foreach ($integrations as $integration) {
            $items[] = [
                'id' => $integration->id,
                'name' => $integration->name,
                'status' => $integration->status,
                'heartbeat_state' => $integration->heartbeat_state ?? WhmcsIntegration::HEARTBEAT_STATE_UNKNOWN,
                'last_heartbeat_at' => $integration->last_heartbeat_at?->toIso8601String(),
                'last_seen_at' => $integration->last_seen_at?->toIso8601String(),
                'runs_count' => (int) $integration->diagnostic_runs_count,
                'open_findings' => $this->tenantRecords->findings($user)
                    ->currentIssues()
                    ->whereHas('diagnosticRun', fn ($query) => $query->where('integration_id', $integration->id))
                    ->active()
                    ->count(),
                'addon_version' => $integration->addon_version,
            ];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function tenantIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values($user->tenantMemberships()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->map(fn ($id): string => (string) $id)
            ->all());
    }

    private function primaryTenant(?User $user): ?Tenant
    {
        if ($user === null) {
            return null;
        }

        $membership = $user->tenantMemberships()
            ->where('status', 'active')
            ->whereHas('tenant', fn ($q) => $q->where('status', Tenant::STATUS_ACTIVE))
            ->with('tenant')
            ->orderBy('created_at')
            ->first();

        return $membership?->tenant;
    }
}

<?php

namespace App\Services\Reports;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\WhmcsHealthSnapshot;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FixedIssueCounter;
use App\Services\Health\TimeSavedCalculator;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

class ReportMetricsService
{
    public function __construct(
        private readonly TimeSavedCalculator $timeSaved,
        private readonly FindingReportCtaResolver $ctaResolver,
        private readonly FixedIssueCounter $fixedIssues,
    ) {}

    /**
     * @return array{
     *     tenant_name: string,
     *     period_start: string,
     *     period_end: string,
     *     health_score: int|null,
     *     health_score_previous: int|null,
     *     health_score_change: int|null,
     *     issues_fixed: int,
     *     open: int,
     *     in_progress: int,
     *     critical: int,
     *     accepted_risk: int,
     *     awaiting_safe_actions: int,
     *     needs_recheck: int,
     *     time_saved_minutes: int,
     *     time_saved_hours: float,
     *     time_saved_hours_display: string,
     *     time_saved_value_usd: float,
     *     time_saved_value_display: string,
     *     hourly_rate_usd: int,
     *     recovered_usd: float|null,
     *     recovered_display: string|null,
     *     outside_heartbeat_incidents: int,
     *     top_open_issues: list<array{id: string, title: string, severity: string, status: string, integration_name: string, fix_url: string, cta: string, cta_label: string}>,
     *     installations: list<array{id: string, name: string, heartbeat_state: string, last_heartbeat_at: string|null}>,
     *     findings_url: string,
     *     safe_actions_url: string,
     *     reports_url: string,
     *     top_findings: list<array{title: string, severity: string, status: string, integration_name: string}>,
     *     resolved_this_week: int
     * }
     */
    public function build(Tenant $tenant, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $start = $periodStart->copy()->utc()->startOfDay();
        $end = $periodEnd->copy()->utc()->endOfDay();

        $base = DiagnosticFinding::query()->where('tenant_id', $tenant->id);
        $current = (clone $base)->currentIssues();

        $topOpen = (clone $current)
            ->active()
            ->with('diagnosticRun.integration:id,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        $topOpenIssues = array_values($topOpen->map(function (DiagnosticFinding $finding) use ($tenant): array {
            $cta = $this->ctaResolver->forFinding($finding, $tenant);

            return [
                'id' => $finding->id,
                'title' => $finding->title,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'integration_name' => $finding->diagnosticRun->integration->name ?? 'Unknown',
                'fix_url' => URL::route('findings.show', $finding),
                'cta' => $cta['cta'],
                'cta_label' => $cta['cta_label'],
            ];
        })->all());

        // Legacy digest shape (top 8 without deep links) kept for mail BC.
        $topFindings = array_values((clone $current)
            ->active()
            ->with('diagnosticRun.integration:id,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (DiagnosticFinding $finding): array => [
                'title' => $finding->title,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'integration_name' => $finding->diagnosticRun->integration->name ?? 'Unknown',
            ])
            ->all());

        $issuesFixed = $this->fixedIssues->countForTenant($tenant, $start, $end);

        $awaitingSafeActions = SafeActionExecution::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', SafeActionExecution::STATUS_AWAITING_AGENT)
            ->count();

        $needsRecheck = SafeActionExecution::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', SafeActionExecution::STATUS_COMPLETED)
            ->count();

        $timeSaved = $this->timeSaved->forTenantPeriod($tenant, $start, $end);
        $health = $this->healthScores($tenant, $start, $end);
        $recovered = $this->recoveredUsd($tenant, $start, $end);

        $heartbeatIncidents = (clone $base)
            ->notMerged()
            ->where('issue_key', 'heartbeat.missed')
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('created_at', [$start, $end])
                    ->orWhere(function ($resolved) use ($start, $end): void {
                        $resolved->where('status', DiagnosticFinding::STATUS_RESOLVED)
                            ->whereBetween('resolved_at', [$start, $end]);
                    });
            })
            ->count();

        $installations = array_values(WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'heartbeat_state', 'last_heartbeat_at'])
            ->map(fn (WhmcsIntegration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'heartbeat_state' => $integration->heartbeat_state ?? WhmcsIntegration::HEARTBEAT_STATE_UNKNOWN,
                'last_heartbeat_at' => $integration->last_heartbeat_at?->toIso8601String(),
            ])
            ->all());

        $open = (clone $current)->where('status', DiagnosticFinding::STATUS_OPEN)->count();
        $inProgress = (clone $current)->where('status', DiagnosticFinding::STATUS_IN_PROGRESS)->count();
        $critical = (clone $current)->active()->where('severity', 'critical')->count();

        return [
            'tenant_name' => $tenant->name,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'health_score' => $health['current'],
            'health_score_previous' => $health['previous'],
            'health_score_change' => $health['change'],
            'issues_fixed' => $issuesFixed,
            'open' => $open,
            'in_progress' => $inProgress,
            'critical' => $critical,
            'accepted_risk' => (clone $current)->where('status', DiagnosticFinding::STATUS_ACCEPTED_RISK)->count(),
            'awaiting_safe_actions' => $awaitingSafeActions,
            'needs_recheck' => $needsRecheck,
            'time_saved_minutes' => $timeSaved['minutes'],
            'time_saved_hours' => $timeSaved['hours'],
            'time_saved_hours_display' => $timeSaved['hours_display'],
            'time_saved_value_usd' => $timeSaved['value_usd'],
            'time_saved_value_display' => $timeSaved['value_display'],
            'hourly_rate_usd' => $timeSaved['hourly_rate_usd'],
            'recovered_usd' => $recovered['amount'],
            'recovered_display' => $recovered['display'],
            'outside_heartbeat_incidents' => $heartbeatIncidents,
            'top_open_issues' => $topOpenIssues,
            'installations' => $installations,
            'findings_url' => URL::route('findings.index', ['status' => 'active']),
            'safe_actions_url' => URL::route('safe-actions.index'),
            'reports_url' => URL::route('reports.index'),
            'top_findings' => $topFindings,
            'resolved_this_week' => $issuesFixed,
        ];
    }

    /**
     * @return array{current: int|null, previous: int|null, change: int|null}
     */
    private function healthScores(Tenant $tenant, CarbonInterface $start, CarbonInterface $end): array
    {
        $current = $this->averageLatestScoreOnOrBefore($tenant, $end);
        $previous = $this->averageLatestScoreOnOrBefore($tenant, $start->copy()->subSecond());

        if ($current === null) {
            return ['current' => null, 'previous' => $previous, 'change' => null];
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $previous === null ? null : ($current - $previous),
        ];
    }

    private function averageLatestScoreOnOrBefore(Tenant $tenant, CarbonInterface $at): ?int
    {
        $integrationIds = WhmcsIntegration::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('id');

        if ($integrationIds->isEmpty()) {
            return null;
        }

        $scores = [];
        foreach ($integrationIds as $integrationId) {
            $snapshot = WhmcsHealthSnapshot::query()
                ->where('tenant_id', $tenant->id)
                ->where('integration_id', $integrationId)
                ->where('captured_at', '<=', $at)
                ->orderByDesc('captured_at')
                ->first(['score']);

            if ($snapshot !== null) {
                $scores[] = (int) $snapshot->score;
            }
        }

        if ($scores === []) {
            return null;
        }

        return (int) round(array_sum($scores) / count($scores));
    }

    /**
     * @return array{amount: float|null, display: string|null}
     */
    private function recoveredUsd(Tenant $tenant, CarbonInterface $start, CarbonInterface $end): array
    {
        $runs = DiagnosticRun::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('received_at', [$start, $end])
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

        if (! $hasData) {
            return ['amount' => null, 'display' => null];
        }

        $rounded = round($total, 2);

        return [
            'amount' => $rounded,
            'display' => number_format($rounded, 2, '.', ''),
        ];
    }
}

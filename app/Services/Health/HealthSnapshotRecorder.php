<?php

namespace App\Services\Health;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\WhmcsHealthSnapshot;
use App\Models\WhmcsIntegration;
use App\Services\Diagnostics\FindingContinuityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HealthSnapshotRecorder
{
    public function __construct(
        private readonly HealthScoreCalculator $calculator,
        private readonly FindingContinuityService $continuity,
    ) {}

    public function recomputeForIntegration(WhmcsIntegration $integration, ?DiagnosticRun $run = null): ?WhmcsHealthSnapshot
    {
        if ($integration->tenant_id === null) {
            return null;
        }

        $run ??= $this->latestRun($integration);
        $findings = $this->openFindings($integration);
        $counts = $this->severityCounts($findings);
        /** @var list<array{severity: string, affected_count?: int|null}> $findingPayload */
        $findingPayload = [];
        foreach ($findings as $finding) {
            $findingPayload[] = [
                'severity' => $finding->severity,
                'affected_count' => $finding->affected_count,
            ];
        }

        $score = $this->calculator->calculate(
            $findingPayload,
            $integration->heartbeat_state,
        );

        $capturedAt = now('UTC');
        $capturedOn = $capturedAt->toDateString();
        $metrics = $this->metrics($integration, $run);

        return DB::transaction(function () use ($integration, $run, $score, $counts, $capturedAt, $capturedOn, $metrics): WhmcsHealthSnapshot {
            $existing = WhmcsHealthSnapshot::query()
                ->where('integration_id', $integration->id)
                ->whereDate('captured_on', $capturedOn)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'tenant_id' => $integration->tenant_id,
                'integration_id' => $integration->id,
                'diagnostic_run_id' => $run?->id,
                'captured_at' => $capturedAt,
                'captured_on' => $capturedOn,
                'score' => $score,
                'critical_count' => $counts['critical'],
                'warning_count' => $counts['warning'],
                'info_count' => $counts['info'],
                'metrics' => $metrics,
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();

                return $existing->refresh();
            }

            return WhmcsHealthSnapshot::query()->create($attributes);
        });
    }

    public function recomputeForIntegrationId(string $integrationId): ?WhmcsHealthSnapshot
    {
        $integration = WhmcsIntegration::query()->find($integrationId);

        return $integration === null ? null : $this->recomputeForIntegration($integration);
    }

    /**
     * @return Collection<int, DiagnosticFinding>
     */
    private function openFindings(WhmcsIntegration $integration)
    {
        $query = DiagnosticFinding::query()
            ->where('tenant_id', $integration->tenant_id)
            ->whereHas('diagnosticRun', fn ($q) => $q->where('integration_id', $integration->id))
            ->active()
            ->notIgnored();

        return $this->continuity->scopeCurrentIssues($query)->get();
    }

    /**
     * @param  Collection<int, DiagnosticFinding>  $findings
     * @return array{critical: int, warning: int, info: int}
     */
    private function severityCounts($findings): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $severity = strtolower((string) $finding->severity);
            if ($severity === 'critical') {
                $counts['critical']++;
            } elseif ($severity === 'warning') {
                $counts['warning']++;
            } else {
                $counts['info']++;
            }
        }

        return $counts;
    }

    /**
     * @return array{
     *     module_queue_pending: int|null,
     *     overdue_unpaid_count: int|null,
     *     suspended_with_paid: int|null,
     *     sla_breaches: int|null,
     *     domains_failing: int|null,
     *     heartbeat_state: string,
     *     disk_free_percent: float|null
     * }
     */
    private function metrics(WhmcsIntegration $integration, ?DiagnosticRun $run): array
    {
        $payload = is_array($run?->payload) ? $run->payload : [];

        $moduleQueue = data_get($payload, 'whmcs.module_queue.pending_count');
        if ($moduleQueue === null) {
            $moduleQueue = data_get($payload, 'whmcs.module_queue.pending');
        }

        $domainsFailing = data_get($payload, 'whmcs.domains.failing_count');
        if ($domainsFailing === null && is_array(data_get($payload, 'whmcs.domains'))) {
            $domainsFailing = (int) (data_get($payload, 'whmcs.domains.renewal_failed.count') ?? 0)
                + (int) (data_get($payload, 'whmcs.domains.sync_errors.count') ?? 0)
                + (int) (data_get($payload, 'whmcs.domains.active_expired.count') ?? 0);
        }

        $sla = data_get($payload, 'whmcs.tickets.sla_breaches');
        if (is_array($sla)) {
            $sla = $sla['count'] ?? count($sla['ticket_ids'] ?? []);
        }

        $suspended = data_get($payload, 'whmcs.services.suspended_with_paid_invoices.count');

        $disk = data_get($payload, 'host.disk_free_percent');

        return [
            'module_queue_pending' => is_numeric($moduleQueue) ? (int) $moduleQueue : null,
            'overdue_unpaid_count' => is_numeric(data_get($payload, 'whmcs.billing.overdue_unpaid_count'))
                ? (int) data_get($payload, 'whmcs.billing.overdue_unpaid_count')
                : null,
            'suspended_with_paid' => is_numeric($suspended) ? (int) $suspended : null,
            'sla_breaches' => is_numeric($sla) ? (int) $sla : null,
            'domains_failing' => is_numeric($domainsFailing) ? (int) $domainsFailing : null,
            'heartbeat_state' => (string) ($integration->heartbeat_state ?? WhmcsIntegration::HEARTBEAT_STATE_UNKNOWN),
            'disk_free_percent' => is_numeric($disk) ? round((float) $disk, 2) : null,
        ];
    }

    private function latestRun(WhmcsIntegration $integration): ?DiagnosticRun
    {
        return DiagnosticRun::query()
            ->where('integration_id', $integration->id)
            ->latest('received_at')
            ->first();
    }
}

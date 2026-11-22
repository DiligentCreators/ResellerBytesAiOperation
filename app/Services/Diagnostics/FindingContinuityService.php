<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class FindingContinuityService
{
    public function __construct(private readonly FindingWorkflowService $workflow) {}

    /**
     * @param  array{
     *     category: string,
     *     title: string,
     *     summary: string,
     *     evidence_paths: list<string>,
     *     severity: string,
     *     confidence: mixed,
     *     recommendations: list<array{recommendation: string, rationale: string, risk_level: string}>
     * }  $findingData
     * @return array{
     *     category: string,
     *     title: string,
     *     summary: string,
     *     evidence_paths: list<string>,
     *     severity: string,
     *     confidence: mixed,
     *     recommendations: list<array{recommendation: string, rationale: string, risk_level: string}>
     * }
     */
    public function stabilizeFindingData(array $findingData): array
    {
        $paths = array_values(array_unique($findingData['evidence_paths']));
        sort($paths, SORT_STRING);
        $findingData['evidence_paths'] = $paths;

        if (in_array('system.error_display_disabled', $paths, true)) {
            $findingData['title'] = 'PHP error display is not disabled';
        }

        if (in_array('system.configuration_file_writable', $paths, true)) {
            $findingData['title'] = 'WHMCS configuration file is writable';
        }

        return $findingData;
    }

    /**
     * @param  array{category: string, title: string, evidence_paths: list<string>}  $findingData
     */
    public function fingerprintFor(array $findingData): string
    {
        $paths = array_values(array_unique($findingData['evidence_paths']));
        sort($paths, SORT_STRING);

        return hash('sha256', implode("\n", [
            $findingData['category'],
            mb_strtolower($findingData['title']),
            implode('|', $paths),
        ]));
    }

    /**
     * Stable fingerprint for catalog-backed rule findings (issue_key is the continuity key).
     *
     * @param  array{category: string, title: string, evidence_paths?: list<string>}  $findingData
     */
    public function fingerprintForIssueKey(string $issueKey, array $findingData): string
    {
        return hash('sha256', implode("\n", [
            'rule',
            $issueKey,
            $findingData['category'],
            mb_strtolower($findingData['title']),
        ]));
    }

    public function markAppliedAwaitingVerify(DiagnosticFinding $finding, User $actor, string $actionKey): void
    {
        if (! $finding->isActive()) {
            return;
        }

        $this->workflow->update($finding, $actor, [
            'status' => DiagnosticFinding::STATUS_IN_PROGRESS,
            'assignee_user_id' => $finding->assignee_user_id ?? $actor->id,
            'status_note' => 'Applied; waiting for verify scan after Safe Action '.$actionKey.'.',
        ]);
    }

    public function resolveProvenSiblings(DiagnosticFinding $finding, User $actor, string $actionKey): void
    {
        $finding->loadMissing('diagnosticRun');
        $integrationId = $finding->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return;
        }

        $siblings = DiagnosticFinding::query()
            ->where('fingerprint', $finding->fingerprint)
            ->active()
            ->whereHas('diagnosticRun', fn (Builder $query) => $query->where('integration_id', $integrationId))
            ->orderBy('created_at')
            ->get();

        foreach ($siblings as $sibling) {
            $this->workflow->update($sibling, $actor, [
                'status' => DiagnosticFinding::STATUS_RESOLVED,
                'assignee_user_id' => $sibling->assignee_user_id,
                'status_note' => 'Auto-resolved after verified Safe Action '.$actionKey.'.',
            ]);
        }
    }

    public function inheritActiveWorkflow(DiagnosticFinding $newFinding): void
    {
        $newFinding->loadMissing('diagnosticRun');
        $integrationId = $newFinding->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return;
        }

        $previous = DiagnosticFinding::query()
            ->where('fingerprint', $newFinding->fingerprint)
            ->whereKeyNot($newFinding->id)
            ->whereHas('diagnosticRun', fn (Builder $query) => $query->where('integration_id', $integrationId))
            ->where('status', DiagnosticFinding::STATUS_IN_PROGRESS)
            ->latest('created_at')
            ->first();

        if ($previous === null) {
            return;
        }

        $newFinding->forceFill([
            'status' => DiagnosticFinding::STATUS_IN_PROGRESS,
            'assignee_user_id' => $previous->assignee_user_id,
            'status_note' => $previous->status_note,
            'status_updated_at' => now(),
        ])->save();
    }

    public function clearAbsentActiveFindings(DiagnosticRun $analyzedRun, User $actor): void
    {
        $presentFingerprints = $analyzedRun->findings()->pluck('fingerprint')->all();

        $absent = DiagnosticFinding::query()
            ->active()
            ->whereHas('diagnosticRun', function (Builder $query) use ($analyzedRun): void {
                $query->where('integration_id', $analyzedRun->integration_id)
                    ->where('id', '!=', $analyzedRun->id);
            })
            ->when(
                $presentFingerprints !== [],
                fn (Builder $query) => $query->whereNotIn('fingerprint', $presentFingerprints),
            )
            ->get();

        foreach ($absent as $finding) {
            $this->workflow->update($finding, $actor, [
                'status' => DiagnosticFinding::STATUS_RESOLVED,
                'assignee_user_id' => $finding->assignee_user_id,
                'status_note' => 'Cleared on latest analyzed scan '.$analyzedRun->id.'.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function blockedSafeActionKeys(DiagnosticFinding $finding): array
    {
        $finding->loadMissing('diagnosticRun');
        $integrationId = $finding->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return [];
        }

        $findingIds = $this->relatedFindingIds($finding, $integrationId);

        $keys = [];
        foreach (
            SafeActionExecution::query()
                ->where('integration_id', $integrationId)
                ->whereIn('finding_id', $findingIds)
                ->whereIn('status', [
                    SafeActionExecution::STATUS_PENDING_APPROVAL,
                    SafeActionExecution::STATUS_APPROVED,
                    SafeActionExecution::STATUS_AWAITING_AGENT,
                    SafeActionExecution::STATUS_COMPLETED,
                ])
                ->pluck('action_key')
                ->unique()
                ->values() as $key
        ) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Latest in-flight safe action for this finding fingerprint (approval or addon wait).
     */
    public function pendingSafeActionExecution(DiagnosticFinding $finding): ?SafeActionExecution
    {
        $finding->loadMissing('diagnosticRun');
        $integrationId = $finding->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return null;
        }

        $findingIds = $this->relatedFindingIds($finding, $integrationId);
        if ($findingIds === []) {
            return null;
        }

        return SafeActionExecution::query()
            ->where('integration_id', $integrationId)
            ->whereIn('finding_id', $findingIds)
            ->whereIn('status', [
                SafeActionExecution::STATUS_PENDING_APPROVAL,
                SafeActionExecution::STATUS_APPROVED,
                SafeActionExecution::STATUS_AWAITING_AGENT,
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Latest completed fix still waiting for the next snapshot to verify.
     */
    public function awaitingVerificationSafeActionExecution(DiagnosticFinding $finding): ?SafeActionExecution
    {
        $finding->loadMissing('diagnosticRun');
        $integrationId = $finding->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return null;
        }

        $findingIds = $this->relatedFindingIds($finding, $integrationId);
        if ($findingIds === []) {
            return null;
        }

        return SafeActionExecution::query()
            ->where('integration_id', $integrationId)
            ->whereIn('finding_id', $findingIds)
            ->where('status', SafeActionExecution::STATUS_COMPLETED)
            ->orderByDesc('executed_at')
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function relatedFindingIds(DiagnosticFinding $finding, string $integrationId): array
    {
        $ids = [];
        foreach (
            DiagnosticFinding::query()
                ->where('fingerprint', $finding->fingerprint)
                ->whereHas('diagnosticRun', fn (Builder $query) => $query->where('integration_id', $integrationId))
                ->pluck('id') as $id
        ) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Keep one row per installation + fingerprint (newest sighting).
     *
     * @param  Builder<DiagnosticFinding>  $query
     * @return Builder<DiagnosticFinding>
     */
    public function scopeCurrentIssues(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereIn($table.'.id', function ($sub) use ($table): void {
            $sub->from($table.' as df')
                ->join('diagnostic_runs as dr', 'dr.id', '=', 'df.diagnostic_run_id')
                ->select('df.id')
                ->whereRaw('df.id = (
                    select df2.id
                    from '.$table.' as df2
                    inner join diagnostic_runs as dr2 on dr2.id = df2.diagnostic_run_id
                    where dr2.integration_id = dr.integration_id
                      and df2.fingerprint = df.fingerprint
                    order by df2.created_at desc, df2.id desc
                    limit 1
                )');
        });
    }

    /**
     * @param  Builder<DiagnosticFinding>  $findingsQuery
     */
    public function countCurrentActiveIssues(Builder $findingsQuery): int
    {
        $active = (clone $findingsQuery)->whereIn('status', DiagnosticFinding::ACTIVE_STATUSES);

        return $this->scopeCurrentIssues($active)->count();
    }
}

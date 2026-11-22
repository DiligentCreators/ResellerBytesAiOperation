<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\SafeActionExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FindingContinuityService
{
    /**
     * Map AI evidence paths to catalog issue keys. A null value means the key is
     * chosen from title keywords (see {@see issueKeyForWebPhpVersionTitle()}).
     *
     * @var array<string, string|null>
     */
    public const AI_EVIDENCE_ISSUE_KEY_MAP = [
        'system.error_display_disabled' => 'php.display_errors_on',
        'system.configuration_file_writable' => 'security.config_writable',
        'system.web_php_version' => null,
    ];

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
     * Map an AI finding onto a catalog issue key when evidence (and title, when
     * needed) match a known deterministic issue.
     *
     * @param  array{category?: string, title?: string, evidence_paths?: list<string>}  $findingData
     */
    public function canonicalIssueKeyFor(array $findingData): ?string
    {
        $paths = $findingData['evidence_paths'] ?? [];
        if (! is_array($paths)) {
            return null;
        }

        $normalizedPaths = [];
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $normalizedPaths[] = $path;
            }
        }

        $title = mb_strtolower((string) ($findingData['title'] ?? ''));

        foreach (self::AI_EVIDENCE_ISSUE_KEY_MAP as $evidencePath => $issueKey) {
            if (! in_array($evidencePath, $normalizedPaths, true)) {
                continue;
            }

            if (is_string($issueKey)) {
                return $issueKey;
            }

            if ($evidencePath === 'system.web_php_version') {
                return $this->issueKeyForWebPhpVersionTitle($title);
            }
        }

        return null;
    }

    /**
     * Stabilize AI finding data and converge mapped findings onto catalog title,
     * category, and issue-key fingerprints shared with rule findings.
     *
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
     *     finding_data: array{
     *         category: string,
     *         title: string,
     *         summary: string,
     *         evidence_paths: list<string>,
     *         severity: string,
     *         confidence: mixed,
     *         recommendations: list<array{recommendation: string, rationale: string, risk_level: string}>
     *     },
     *     issue_key: string|null,
     *     fingerprint: string,
     *     fix_mode: string|null
     * }
     */
    public function resolveAiFindingIdentity(array $findingData): array
    {
        $findingData = $this->stabilizeFindingData($findingData);
        $issueKey = $this->canonicalIssueKeyFor($findingData);

        if ($issueKey !== null) {
            $catalog = WhmcsIssueCatalog::get($issueKey);
            if ($catalog !== null) {
                $findingData['title'] = (string) ($catalog['title'] ?? $findingData['title']);
                $findingData['category'] = (string) ($catalog['category'] ?? $findingData['category']);

                return [
                    'finding_data' => $findingData,
                    'issue_key' => $issueKey,
                    'fingerprint' => $this->fingerprintForIssueKey($issueKey, $findingData),
                    'fix_mode' => is_string($catalog['fix_mode'] ?? null) ? $catalog['fix_mode'] : null,
                ];
            }
        }

        return [
            'finding_data' => $findingData,
            'issue_key' => null,
            'fingerprint' => $this->fingerprintFor($findingData),
            'fix_mode' => null,
        ];
    }

    /**
     * Append an AI summary onto a winning rule finding when it adds new detail.
     */
    public function appendAiSummaryIfUseful(DiagnosticFinding $ruleFinding, string $aiSummary): void
    {
        $aiSummary = trim($aiSummary);
        if ($aiSummary === '') {
            return;
        }

        $existing = trim((string) $ruleFinding->summary);
        if ($existing !== '' && str_contains($existing, $aiSummary)) {
            return;
        }

        $ruleFinding->forceFill([
            'summary' => $existing === '' ? $aiSummary : $existing."\n\n".$aiSummary,
        ])->save();
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

    private function issueKeyForWebPhpVersionTitle(string $lowerTitle): ?string
    {
        if (
            str_contains($lowerTitle, 'end-of-life')
            || str_contains($lowerTitle, 'end of life')
            || str_contains($lowerTitle, 'no longer supported')
            || preg_match('/\beol\b/', $lowerTitle) === 1
        ) {
            return 'php.version_eol';
        }

        if (str_contains($lowerTitle, 'unsupported') || str_contains($lowerTitle, 'compatib')) {
            return 'php.version_unsupported';
        }

        return null;
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

    /**
     * Keep one open copy per integration + fingerprint after a new sighting.
     *
     * Carries in_progress workflow onto the current copy, then resolves earlier
     * active (non-merged) copies via the system actor and marks them merged into
     * the current finding. Idempotent and ordered safely under concurrent ingests.
     */
    public function supersedePriorOpenCopies(DiagnosticFinding $current): void
    {
        $this->inheritActiveWorkflow($current);

        $current->loadMissing('diagnosticRun');
        $integrationId = $current->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return;
        }

        DB::transaction(function () use ($current, $integrationId): void {
            $lockedCurrent = DiagnosticFinding::query()
                ->whereKey($current->id)
                ->lockForUpdate()
                ->first();

            if ($lockedCurrent === null || ! $lockedCurrent->isActive()) {
                return;
            }

            $lockedCurrent->loadMissing('diagnosticRun');
            $currentRun = $lockedCurrent->diagnosticRun;
            if ($currentRun === null) {
                return;
            }

            $priors = DiagnosticFinding::query()
                ->active()
                ->notMerged()
                ->where('fingerprint', $lockedCurrent->fingerprint)
                ->whereKeyNot($lockedCurrent->id)
                ->whereHas('diagnosticRun', function (Builder $query) use ($integrationId, $currentRun): void {
                    $query->where('integration_id', $integrationId)
                        ->where(function (Builder $earlier) use ($currentRun): void {
                            $earlier->where('received_at', '<', $currentRun->received_at)
                                ->orWhere(function (Builder $sameMoment) use ($currentRun): void {
                                    $sameMoment->where('received_at', '=', $currentRun->received_at)
                                        ->where('id', '<', $currentRun->id);
                                });
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($priors as $prior) {
                if (! $prior->isActive() || $prior->merged_into_finding_id !== null) {
                    continue;
                }

                $updated = $this->workflow->update($prior, null, [
                    'status' => DiagnosticFinding::STATUS_RESOLVED,
                    'assignee_user_id' => $prior->assignee_user_id,
                    'status_note' => 'Superseded by finding '.$lockedCurrent->id
                        .' from run '.$lockedCurrent->diagnostic_run_id,
                ]);

                $updated->forceFill([
                    'merged_into_finding_id' => $lockedCurrent->id,
                ])->save();
            }

            $current->refresh();
        });
    }

    /**
     * Follow merged_into_finding_id to the canonical copy, falling back to the
     * newest active sibling for the same integration + fingerprint.
     */
    public function resolveToCurrentCopy(DiagnosticFinding $finding): DiagnosticFinding
    {
        $seen = [];
        $current = $finding;

        while (is_string($current->merged_into_finding_id) && $current->merged_into_finding_id !== '') {
            if (isset($seen[$current->id])) {
                break;
            }
            $seen[$current->id] = true;

            $next = DiagnosticFinding::query()->find($current->merged_into_finding_id);
            if ($next === null) {
                break;
            }

            $current = $next;
        }

        if ($current->isActive()) {
            return $current;
        }

        $current->loadMissing('diagnosticRun');
        $integrationId = $current->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return $current;
        }

        $activeSibling = DiagnosticFinding::query()
            ->where('fingerprint', $current->fingerprint)
            ->active()
            ->notMerged()
            ->whereHas(
                'diagnosticRun',
                fn (Builder $query) => $query->where('integration_id', $integrationId),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $activeSibling ?? $current;
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
     * All finding IDs sharing this fingerprint on the same installation
     * (including superseded copies). Used by Fix Center, guided steps, and
     * safe-action continuity lookups.
     *
     * @return list<string>
     */
    public function relatedFindingIds(DiagnosticFinding $finding, string $integrationId): array
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

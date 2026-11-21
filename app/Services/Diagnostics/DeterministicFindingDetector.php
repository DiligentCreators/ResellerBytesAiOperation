<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRecommendation;
use App\Models\DiagnosticRun;
use App\Models\User;
use App\Services\Diagnostics\Rules\DetectedIssue;
use App\Services\Diagnostics\Rules\IssueRuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DeterministicFindingDetector
{
    public const SOURCE = 'rule';

    public function __construct(
        private readonly IssueRuleRegistry $rules,
        private readonly FindingContinuityService $continuity,
    ) {}

    /**
     * Run catalog rules against a stored snapshot and persist source=rule findings.
     *
     * @return list<DiagnosticFinding>
     */
    public function detect(DiagnosticRun $run, ?User $actor = null): array
    {
        $run->loadMissing('integration');
        $integration = $run->integration;
        if ($integration === null) {
            return [];
        }

        $payload = is_array($run->payload) ? $run->payload : [];
        $created = [];
        $presentFingerprints = [];

        DB::transaction(function () use ($run, $integration, $payload, $actor, &$created, &$presentFingerprints): void {
            $lockedRun = DiagnosticRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($lockedRun === null) {
                return;
            }

            // Replace rule findings for this run so re-detect is idempotent.
            DiagnosticFinding::query()
                ->where('diagnostic_run_id', $lockedRun->id)
                ->where('source', self::SOURCE)
                ->each(function (DiagnosticFinding $finding): void {
                    $finding->recommendations()->delete();
                    $finding->delete();
                });

            foreach ($this->rules->all() as $rule) {
                $issue = $rule->detect($payload, $integration);
                if (! $issue instanceof DetectedIssue) {
                    continue;
                }

                $catalog = WhmcsIssueCatalog::get($issue->key);
                if ($catalog === null) {
                    continue;
                }

                $findingData = [
                    'category' => (string) ($catalog['category'] ?? 'other'),
                    'title' => (string) ($catalog['title'] ?? $issue->key),
                    'summary' => (string) ($catalog['why_it_matters'] ?? $catalog['title'] ?? $issue->key),
                    'evidence_paths' => array_keys($issue->evidence),
                    'severity' => $issue->severity,
                ];

                $fingerprint = $this->continuity->fingerprintForIssueKey($issue->key, $findingData);
                if (isset($presentFingerprints[$fingerprint])) {
                    continue;
                }
                $presentFingerprints[$fingerprint] = true;

                $finding = DiagnosticFinding::create([
                    'diagnostic_run_id' => $lockedRun->id,
                    'tenant_id' => $lockedRun->tenant_id,
                    'fingerprint' => $fingerprint,
                    'issue_key' => $issue->key,
                    'source' => self::SOURCE,
                    'affected_count' => $issue->affectedCount,
                    'fix_mode' => is_string($catalog['fix_mode'] ?? null) ? $catalog['fix_mode'] : null,
                    'category' => $findingData['category'],
                    'severity' => $issue->severity,
                    'title' => $findingData['title'],
                    'summary' => $findingData['summary'],
                    'evidence' => $issue->evidence,
                    'confidence' => 1,
                    'status' => DiagnosticFinding::STATUS_OPEN,
                ]);

                $steps = $catalog['guided_steps'] ?? [];
                if (is_array($steps)) {
                    foreach (array_values($steps) as $step) {
                        if (! is_string($step) || trim($step) === '') {
                            continue;
                        }

                        DiagnosticRecommendation::create([
                            'diagnostic_finding_id' => $finding->id,
                            'tenant_id' => $lockedRun->tenant_id,
                            'recommendation' => $step,
                            'rationale' => is_string($catalog['why_it_matters'] ?? null)
                                ? $catalog['why_it_matters']
                                : $step,
                            'risk_level' => is_string($catalog['risk'] ?? null) ? $catalog['risk'] : 'medium',
                            'requires_manual_action' => true,
                        ]);
                    }
                }

                $this->continuity->inheritActiveWorkflow($finding);
                $created[] = $finding;
            }

            if ($actor !== null) {
                $this->continuity->clearAbsentActiveFindings($lockedRun->fresh() ?? $lockedRun, $actor);
            } else {
                $this->resolveAbsentWithoutActor($lockedRun, array_keys($presentFingerprints));
            }
        });

        return $created;
    }

    /**
     * @param  list<string>  $presentFingerprints
     */
    private function resolveAbsentWithoutActor(DiagnosticRun $analyzedRun, array $presentFingerprints): void
    {
        $absent = DiagnosticFinding::query()
            ->active()
            ->where('source', self::SOURCE)
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
            $finding->forceFill([
                'status' => DiagnosticFinding::STATUS_RESOLVED,
                'status_note' => 'Cleared on latest rule scan '.$analyzedRun->id.'.',
                'status_updated_at' => now(),
                'resolved_at' => now(),
            ])->save();
        }
    }
}

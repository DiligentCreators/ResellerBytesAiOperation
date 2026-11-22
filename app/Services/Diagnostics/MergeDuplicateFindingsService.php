<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\WhmcsIntegration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MergeDuplicateFindingsService
{
    public function __construct(
        private readonly FindingContinuityService $continuity,
        private readonly FindingWorkflowService $workflow,
    ) {}

    /**
     * Find active duplicate finding groups. With $apply=true, resolve losers via
     * FindingWorkflowService (system actor) without deleting rows.
     *
     * @return array{
     *     dry_run: bool,
     *     groups: list<array{
     *         integration_id: string,
     *         canonical_key: string,
     *         kept_finding_id: string,
     *         resolve_finding_ids: list<string>,
     *         active_count: int,
     *         resolve_count: int
     *     }>,
     *     group_count: int,
     *     resolve_count: int,
     *     resolved_count: int
     * }
     */
    public function merge(?string $tenantId, ?string $integrationId, bool $apply): array
    {
        $groups = $this->duplicateGroups($tenantId, $integrationId);
        $resolvedCount = 0;

        if ($apply) {
            foreach ($groups as $group) {
                foreach ($group['resolve_finding_ids'] as $resolveId) {
                    $finding = DiagnosticFinding::query()->whereKey($resolveId)->first();
                    if ($finding === null || ! $finding->isActive()) {
                        continue;
                    }

                    $this->workflow->update($finding, null, [
                        'status' => DiagnosticFinding::STATUS_RESOLVED,
                        'assignee_user_id' => $finding->assignee_user_id,
                        'status_note' => 'Merged into duplicate finding '.$group['kept_finding_id'].' by findings:merge-duplicates',
                    ]);
                    $resolvedCount++;
                }
            }
        }

        return [
            'dry_run' => ! $apply,
            'groups' => $groups,
            'group_count' => count($groups),
            'resolve_count' => array_sum(array_column($groups, 'resolve_count')),
            'resolved_count' => $resolvedCount,
        ];
    }

    /**
     * @return list<array{
     *     integration_id: string,
     *     canonical_key: string,
     *     kept_finding_id: string,
     *     resolve_finding_ids: list<string>,
     *     active_count: int,
     *     resolve_count: int
     * }>
     */
    public function duplicateGroups(?string $tenantId, ?string $integrationId): array
    {
        $findings = DiagnosticFinding::query()
            ->active()
            ->with(['diagnosticRun:id,integration_id'])
            ->when(
                $tenantId !== null,
                fn (Builder $query) => $query->where('tenant_id', $tenantId),
            )
            ->when(
                $integrationId !== null,
                fn (Builder $query) => $query->whereHas(
                    'diagnosticRun',
                    fn (Builder $run) => $run->where('integration_id', $integrationId),
                ),
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /** @var Collection<string, Collection<int, DiagnosticFinding>> $grouped */
        $grouped = $findings
            ->filter(fn (DiagnosticFinding $finding): bool => $finding->diagnosticRun?->integration_id !== null)
            ->groupBy(function (DiagnosticFinding $finding): string {
                $integrationId = (string) $finding->diagnosticRun?->integration_id;
                [$fingerprint] = $this->canonicalIdentity($finding);

                return $integrationId."\0".$fingerprint;
            })
            ->filter(fn (Collection $bucket): bool => $bucket->count() > 1);

        $groups = [];

        foreach ($grouped as $bucket) {
            /** @var Collection<int, DiagnosticFinding> $bucket */
            $sorted = $bucket->sort(function (DiagnosticFinding $a, DiagnosticFinding $b): int {
                $sourceRank = $this->sourceRank($a) <=> $this->sourceRank($b);
                if ($sourceRank !== 0) {
                    return $sourceRank;
                }

                $created = $b->created_at?->getTimestamp() <=> $a->created_at?->getTimestamp();
                if ($created !== 0) {
                    return $created;
                }

                return strcmp((string) $b->id, (string) $a->id);
            })->values();

            $kept = $sorted->first();
            if ($kept === null) {
                continue;
            }

            $resolveIds = [];
            foreach ($sorted->slice(1) as $candidate) {
                $candidateId = $candidate->id;
                if (is_string($candidateId) && $candidateId !== '') {
                    $resolveIds[] = $candidateId;
                }
            }

            if ($resolveIds === []) {
                continue;
            }

            [, $issueKey, $fingerprint] = $this->canonicalIdentity($kept);
            $integrationIdValue = (string) $kept->diagnosticRun?->integration_id;

            $groups[] = [
                'integration_id' => $integrationIdValue,
                'canonical_key' => $issueKey ?? $fingerprint,
                'kept_finding_id' => (string) $kept->id,
                'resolve_finding_ids' => $resolveIds,
                'active_count' => $sorted->count(),
                'resolve_count' => count($resolveIds),
            ];
        }

        usort($groups, function (array $a, array $b): int {
            $byIntegration = strcmp($a['integration_id'], $b['integration_id']);
            if ($byIntegration !== 0) {
                return $byIntegration;
            }

            return strcmp($a['canonical_key'], $b['canonical_key']);
        });

        return $groups;
    }

    public function integrationExists(string $integrationId): bool
    {
        return WhmcsIntegration::query()->whereKey($integrationId)->exists();
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function canonicalIdentity(DiagnosticFinding $finding): array
    {
        $storedFingerprint = (string) $finding->fingerprint;

        if ($finding->source === DiagnosticFinding::SOURCE_AI) {
            $evidence = is_array($finding->evidence) ? $finding->evidence : [];
            $paths = [];
            foreach (array_keys($evidence) as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }

            $findingData = [
                'category' => (string) $finding->category,
                'title' => (string) $finding->title,
                'evidence_paths' => $paths,
            ];

            $issueKey = $this->continuity->canonicalIssueKeyFor($findingData);
            if ($issueKey !== null) {
                $catalog = WhmcsIssueCatalog::get($issueKey);
                if ($catalog !== null) {
                    $fingerprint = $this->continuity->fingerprintForIssueKey($issueKey, [
                        'category' => (string) ($catalog['category'] ?? $finding->category),
                        'title' => (string) ($catalog['title'] ?? $finding->title),
                    ]);

                    return [$fingerprint, $issueKey, $fingerprint];
                }
            }
        }

        $issueKey = is_string($finding->issue_key) && $finding->issue_key !== ''
            ? $finding->issue_key
            : null;

        return [$storedFingerprint, $issueKey, $storedFingerprint];
    }

    private function sourceRank(DiagnosticFinding $finding): int
    {
        return $finding->source === DiagnosticFinding::SOURCE_RULE ? 0 : 1;
    }
}

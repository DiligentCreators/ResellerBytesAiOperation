<?php

namespace App\Services\Dashboard;

use App\Models\DiagnosticFinding;
use App\Services\Diagnostics\WhmcsIssueCatalog;
use Illuminate\Support\Collection;

class TopOpenFindingSelector
{
    /**
     * Rank open findings by severity (critical first), then catalog priority (lower wins).
     *
     * @param  Collection<int, DiagnosticFinding>|iterable<DiagnosticFinding>  $findings
     * @return array{id: string, title: string, severity: string}|null
     */
    public function select(iterable $findings): ?array
    {
        $collection = $findings instanceof Collection
            ? $findings
            : collect($findings);

        if ($collection->isEmpty()) {
            return null;
        }

        $ranked = $collection->map(function (DiagnosticFinding $finding): array {
            $issueKey = $finding->issue_key;
            $priority = is_string($issueKey) && $issueKey !== ''
                ? WhmcsIssueCatalog::getInt($issueKey, 'priority', 999)
                : 999;
            $severityRank = match (strtolower((string) $finding->severity)) {
                'critical' => 1,
                'warning' => 2,
                default => 3,
            };

            $title = is_string($issueKey) && $issueKey !== ''
                ? (WhmcsIssueCatalog::getString($issueKey, 'title') ?? $finding->title)
                : $finding->title;

            return [
                'id' => (string) $finding->id,
                'title' => (string) $title,
                'severity' => (string) $finding->severity,
                'severity_rank' => $severityRank,
                'priority' => $priority,
            ];
        })->sortBy([
            ['severity_rank', 'asc'],
            ['priority', 'asc'],
        ])->values();

        $top = $ranked->first();
        if (! is_array($top)) {
            return null;
        }

        return [
            'id' => (string) $top['id'],
            'title' => (string) $top['title'],
            'severity' => (string) $top['severity'],
        ];
    }
}

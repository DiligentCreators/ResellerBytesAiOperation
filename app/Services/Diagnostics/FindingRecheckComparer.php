<?php

namespace App\Services\Diagnostics;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;

class FindingRecheckComparer
{
    /**
     * Compare this finding’s fingerprint against the newest analyzed run for the same integration.
     *
     * @return array{
     *     status: 'same_run'|'still_present'|'absent'|'no_newer_run'|'latest_not_analyzed',
     *     message: string,
     *     latest_run: array{id: string, status: string, received_at: string|null, findings_count: int}|null,
     *     matched_finding: array{id: string, title: string, severity: string, status: string}|null
     * }
     */
    public function compare(DiagnosticFinding $finding): array
    {
        $finding->loadMissing('diagnosticRun');
        $run = $finding->diagnosticRun;

        $latest = DiagnosticRun::query()
            ->where('integration_id', $run->integration_id)
            ->when(
                $run->tenant_id !== null,
                fn ($query) => $query->where('tenant_id', $run->tenant_id),
                fn ($query) => $query->whereNull('tenant_id'),
            )
            ->latest('received_at')
            ->first();

        if ($latest === null) {
            return [
                'status' => 'no_newer_run',
                'message' => 'No diagnostic runs were found for this installation.',
                'latest_run' => null,
                'matched_finding' => null,
            ];
        }

        $latestPayload = [
            'id' => $latest->id,
            'status' => $latest->status,
            'received_at' => $latest->received_at?->toIso8601String(),
            'findings_count' => $latest->findings()->count(),
        ];

        if ($latest->id === $run->id) {
            return [
                'status' => 'same_run',
                'message' => 'This finding comes from the latest snapshot. Collect a new WHMCS diagnostic, then re-check to see a before/after diff.',
                'latest_run' => $latestPayload,
                'matched_finding' => null,
            ];
        }

        if ($latest->status !== 'analyzed') {
            return [
                'status' => 'latest_not_analyzed',
                'message' => 'A newer snapshot exists but analysis is not finished yet. Wait for analysis, then re-check.',
                'latest_run' => $latestPayload,
                'matched_finding' => null,
            ];
        }

        $match = DiagnosticFinding::query()
            ->where('diagnostic_run_id', $latest->id)
            ->where('fingerprint', $finding->fingerprint)
            ->first(['id', 'title', 'severity', 'status']);

        if ($match === null) {
            return [
                'status' => 'absent',
                'message' => 'This finding fingerprint is not present on the newest analyzed snapshot. Verification looks clear — mark resolved if you confirmed the fix.',
                'latest_run' => $latestPayload,
                'matched_finding' => null,
            ];
        }

        return [
            'status' => 'still_present',
            'message' => 'The same finding fingerprint is still present on the newest analyzed snapshot.',
            'latest_run' => $latestPayload,
            'matched_finding' => [
                'id' => $match->id,
                'title' => $match->title,
                'severity' => $match->severity,
                'status' => $match->status,
            ],
        ];
    }
}

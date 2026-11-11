<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticRun;
use Inertia\Inertia;
use Inertia\Response;

class DiagnosticRunController extends Controller
{
    public function index(): Response
    {
        $runs = DiagnosticRun::query()
            ->with('integration:id,name')
            ->withCount('findings')
            ->latest('received_at')
            ->paginate(20)
            ->through(fn (DiagnosticRun $run): array => [
                'id' => $run->id,
                'snapshot_uuid' => $run->snapshot_uuid,
                'status' => $run->status,
                'whmcs_version' => data_get($run->payload, 'system.whmcs_version', 'Unknown'),
                'received_at' => $run->received_at?->toIso8601String(),
                'findings_count' => $run->findings_count,
                'integration_name' => $run->integration?->name ?? 'Unknown integration',
            ]);

        return Inertia::render('diagnostic-runs/index', [
            'runs' => $runs,
        ]);
    }

    public function show(DiagnosticRun $diagnosticRun): Response
    {
        $diagnosticRun->load([
            'integration:id,name',
            'findings.recommendations',
        ]);

        return Inertia::render('diagnostic-runs/show', [
            'run' => [
                'id' => $diagnosticRun->id,
                'snapshot_uuid' => $diagnosticRun->snapshot_uuid,
                'schema_version' => $diagnosticRun->schema_version,
                'addon_version' => $diagnosticRun->addon_version,
                'status' => $diagnosticRun->status,
                'collected_at' => $diagnosticRun->collected_at?->toIso8601String(),
                'received_at' => $diagnosticRun->received_at?->toIso8601String(),
                'payload' => $diagnosticRun->payload,
                'integration_name' => $diagnosticRun->integration?->name ?? 'Unknown integration',
                'findings' => $diagnosticRun->findings->map(fn ($finding): array => [
                    'id' => $finding->id,
                    'title' => $finding->title,
                    'severity' => $finding->severity,
                    'status' => $finding->status,
                    'summary' => $finding->summary,
                ]),
            ],
        ]);
    }
}

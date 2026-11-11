<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeDiagnosticRun;
use App\Models\AuditEvent;
use App\Models\DiagnosticRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
                'integration_name' => $run->integration->name,
            ]);

        return Inertia::render('diagnostic-runs/index', [
            'runs' => $runs,
        ]);
    }

    public function reanalyze(DiagnosticRun $diagnosticRun): RedirectResponse
    {
        abort_unless(filled(config('services.openai.key')), 503, 'AI analysis is not configured.');

        if (! in_array($diagnosticRun->status, ['received', 'analysis_failed'], true)) {
            return back()->withErrors([
                'analysis' => 'This diagnostic run is already being analyzed or has completed analysis.',
            ]);
        }

        AnalyzeDiagnosticRun::dispatch($diagnosticRun->id)->afterCommit();
        $diagnosticRun->forceFill(['status' => 'queued'])->save();

        AuditEvent::create([
            'actor_type' => 'user',
            'actor_id' => (string) Auth::id(),
            'event_type' => 'diagnostic_run.analysis_requested',
            'subject_type' => 'diagnostic_run',
            'subject_id' => $diagnosticRun->id,
            'metadata' => [],
            'occurred_at' => now(),
        ]);

        return back()->with('success', 'Diagnostic analysis has been queued.');
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
                'integration_name' => $diagnosticRun->integration->name,
                'ai_configured' => filled(config('services.openai.key')),
                'analysis_summary' => $diagnosticRun->analysis_summary,
                'analysis_error_code' => $diagnosticRun->analysis_error_code,
                'no_findings_reason' => $diagnosticRun->no_findings_reason,
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

<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeDiagnosticRun;
use App\Services\Audit\AuditEventWriter;
use App\Models\DiagnosticRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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

        $queued = DB::transaction(function () use ($diagnosticRun): bool {
            $run = DiagnosticRun::query()
                ->whereKey($diagnosticRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($run->status, ['received', 'analysis_failed'], true)) {
                return false;
            }

            $run->forceFill(['status' => 'queued'])->save();

            app(AuditEventWriter::class)->append([
                'actor_type' => 'user',
                'actor_id' => (string) Auth::id(),
                'event_type' => 'diagnostic_run.analysis_requested',
                'subject_type' => 'diagnostic_run',
                'subject_id' => $run->id,
                'metadata' => [],
                'occurred_at' => now(),
            ]);

            return true;
        });

        if (! $queued) {
            return back()->withErrors([
                'analysis' => 'This diagnostic run is already being analyzed, queued, or has completed analysis.',
            ]);
        }

        try {
            AnalyzeDiagnosticRun::dispatch($diagnosticRun->id)->afterCommit();
        } catch (Throwable $exception) {
            DiagnosticRun::query()
                ->whereKey($diagnosticRun->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'analysis_failed',
                    'updated_at' => now(),
                ]);

            Log::warning('Manual diagnostic analysis could not be queued.', [
                'diagnostic_run_id' => $diagnosticRun->id,
                'exception_class' => $exception::class,
            ]);

            return back()->withErrors([
                'analysis' => 'The analysis queue is unavailable. The snapshot has been preserved.',
            ]);
        }

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

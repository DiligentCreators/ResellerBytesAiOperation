<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticFinding;
use Inertia\Inertia;
use Inertia\Response;

class FindingController extends Controller
{
    public function index(): Response
    {
        $findings = DiagnosticFinding::query()
            ->with('diagnosticRun.integration:id,name')
            ->latest()
            ->paginate(20)
            ->through(fn (DiagnosticFinding $finding): array => [
                'id' => $finding->id,
                'title' => $finding->title,
                'category' => $finding->category,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'created_at' => $finding->created_at?->toIso8601String(),
                'integration_name' => $finding->diagnosticRun->integration->name,
                'diagnostic_run_id' => $finding->diagnostic_run_id,
            ]);

        return Inertia::render('findings/index', [
            'findings' => $findings,
        ]);
    }

    public function show(DiagnosticFinding $finding): Response
    {
        $finding->load([
            'diagnosticRun.integration:id,name',
            'recommendations',
        ]);

        return Inertia::render('findings/show', [
            'finding' => [
                'id' => $finding->id,
                'title' => $finding->title,
                'category' => $finding->category,
                'severity' => $finding->severity,
                'status' => $finding->status,
                'summary' => $finding->summary,
                'evidence' => $finding->evidence,
                'confidence' => $finding->confidence,
                'created_at' => $finding->created_at?->toIso8601String(),
                'integration_name' => $finding->diagnosticRun->integration->name,
                'diagnostic_run_id' => $finding->diagnostic_run_id,
                'recommendations' => $finding->recommendations->map(fn ($recommendation): array => [
                    'id' => $recommendation->id,
                    'recommendation' => $recommendation->recommendation,
                    'rationale' => $recommendation->rationale,
                    'risk_level' => $recommendation->risk_level,
                    'requires_manual_action' => $recommendation->requires_manual_action,
                ]),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Models\WhmcsIntegration;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $latestRuns = DiagnosticRun::query()
            ->with('integration:id,name')
            ->withCount('findings')
            ->latest('received_at')
            ->limit(6)
            ->get()
            ->map(fn (DiagnosticRun $run): array => [
                'id' => $run->id,
                'snapshot_uuid' => $run->snapshot_uuid,
                'status' => $run->status,
                'collected_at' => $run->collected_at?->toIso8601String(),
                'received_at' => $run->received_at?->toIso8601String(),
                'whmcs_version' => data_get($run->payload, 'system.whmcs_version', 'Unknown'),
                'findings_count' => $run->findings_count,
                'integration_name' => $run->integration->name,
            ]);

        return Inertia::render('dashboard', [
            'stats' => [
                'active_integrations' => WhmcsIntegration::query()->where('status', 'active')->count(),
                'total_runs' => DiagnosticRun::query()->count(),
                'open_findings' => DiagnosticFinding::query()->where('status', 'open')->count(),
                'critical_findings' => DiagnosticFinding::query()
                    ->where('status', 'open')
                    ->where('severity', 'critical')
                    ->count(),
            ],
            'latestRuns' => $latestRuns,
            'addon' => [
                'version' => config('services.whmcs_addon.version', '1.0.0'),
                'download_available' => class_exists(\ZipArchive::class),
                'ai_configured' => filled(config('services.openai.key')),
            ],
        ]);
    }
}

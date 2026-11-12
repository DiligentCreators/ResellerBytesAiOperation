<?php

namespace App\Http\Controllers;

use App\Models\WhmcsIntegration;
use Inertia\Inertia;
use Inertia\Response;

class WhmcsIntegrationController extends Controller
{
    public function index(): Response
    {
        $integrations = WhmcsIntegration::query()
            ->withCount('diagnosticRuns')
            ->latest()
            ->paginate(20)
            ->through(fn (WhmcsIntegration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'status' => $integration->status,
                'addon_version' => $integration->addon_version,
                'last_seen_at' => $integration->last_seen_at?->toIso8601String(),
                'diagnostic_runs_count' => $integration->diagnostic_runs_count,
            ]);

        return Inertia::render('integrations/index', [
            'integrations' => $integrations,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantReportSend;
use App\Models\User;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportShowController extends Controller
{
    public function __invoke(
        Request $request,
        Tenant $tenant,
        TenantReportSend $report,
        TenantRoleAuthorization $authorization,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        abort_unless($report->tenant_id === $tenant->id, 404);
        $authorization->require($user, $tenant->id, 'tenant.read');

        return Inertia::render('reports/show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'timezone' => $tenant->timezone ?: config('app.timezone', 'UTC'),
            ],
            'report' => [
                'id' => $report->id,
                'type' => $report->type,
                'period_start' => $report->period_start?->toDateString(),
                'period_end' => $report->period_end?->toDateString(),
                'sent_at' => $report->sent_at?->toIso8601String(),
                'summary' => $report->summary,
            ],
        ]);
    }
}

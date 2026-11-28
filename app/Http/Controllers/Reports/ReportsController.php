<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantReportSend;
use App\Models\User;
use App\Models\WhmcsIntegration;
use App\Services\Reports\FixLogQuery;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportsController extends Controller
{
    public function __invoke(
        Request $request,
        FixLogQuery $fixLog,
        TenantRoleAuthorization $authorization,
    ): Response {
        /** @var User $user */
        $user = Auth::user();
        $tenant = $this->resolveTenant($request, $user, $authorization);

        $filters = $request->validate([
            'mode' => ['nullable', 'string', 'in:'.implode(',', [
                SafeActionExecution::MODE_ONE_CLICK,
                SafeActionExecution::MODE_BULK,
                SafeActionExecution::MODE_AUTO,
                SafeActionExecution::MODE_GUIDED,
            ])],
            'result' => ['nullable', 'string', 'in:'.implode(',', SafeActionExecution::STATUSES)],
            'integration_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $reports = TenantReportSend::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('period_start')
            ->limit(50)
            ->get()
            ->map(fn (TenantReportSend $report): array => [
                'id' => $report->id,
                'type' => $report->type,
                'period_start' => $report->period_start?->toDateString(),
                'period_end' => $report->period_end?->toDateString(),
                'sent_at' => $report->sent_at?->toIso8601String(),
                'summary' => [
                    'health_score' => $report->summary['health_score'] ?? null,
                    'issues_fixed' => $report->summary['issues_fixed'] ?? null,
                    'time_saved_hours_display' => $report->summary['time_saved_hours_display'] ?? null,
                    'time_saved_value_display' => $report->summary['time_saved_value_display'] ?? null,
                ],
            ]);

        $fixes = $fixLog->paginate($tenant, $filters, 25);

        return Inertia::render('reports/index', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'timezone' => $tenant->timezone ?: config('app.timezone', 'UTC'),
            ],
            'status' => $request->session()->get('status'),
            'requiredPlanName' => Plan::requiredPlanLabel('reports.value'),
            'reports' => $reports,
            'filters' => [
                'mode' => $filters['mode'] ?? null,
                'result' => $filters['result'] ?? null,
                'integration_id' => $filters['integration_id'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'q' => $filters['q'] ?? null,
            ],
            'integrations' => WhmcsIntegration::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (WhmcsIntegration $integration): array => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                ]),
            'fixLog' => [
                'data' => $fixLog->presentRows($fixes->items(), $tenant),
                'meta' => [
                    'current_page' => $fixes->currentPage(),
                    'last_page' => $fixes->lastPage(),
                    'per_page' => $fixes->perPage(),
                    'total' => $fixes->total(),
                ],
                'links' => [
                    'first' => $fixes->url(1),
                    'last' => $fixes->url(max(1, $fixes->lastPage())),
                    'prev' => $fixes->previousPageUrl(),
                    'next' => $fixes->nextPageUrl(),
                ],
            ],
        ]);
    }

    private function resolveTenant(Request $request, User $user, TenantRoleAuthorization $authorization): Tenant
    {
        $tenantId = $request->string('tenant_id')->toString();
        if ($tenantId === '') {
            $membership = $user->tenantMemberships()
                ->where('status', TenantMembership::STATUS_ACTIVE)
                ->whereHas('tenant', fn ($q) => $q->where('status', Tenant::STATUS_ACTIVE))
                ->with('tenant')
                ->orderBy('created_at')
                ->first();

            if ($membership?->tenant === null) {
                throw new NotFoundHttpException('No active workspace found.');
            }

            $authorization->require($user, $membership->tenant->id, 'tenant.read');

            return $membership->tenant;
        }

        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->firstOrFail();
        $authorization->require($user, $tenant->id, 'tenant.read');

        return $tenant;
    }
}

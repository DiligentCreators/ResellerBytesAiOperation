<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reports\FixLogQuery;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixLogPrintController extends Controller
{
    public function __invoke(
        Request $request,
        Tenant $tenant,
        FixLogQuery $fixLog,
        TenantRoleAuthorization $authorization,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $authorization->require($user, $tenant->id, 'tenant.read');

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

        $rows = $fixLog->baseQuery($tenant, $filters)->limit(500)->get();

        return Inertia::render('reports/fix-log-print', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'timezone' => $tenant->timezone ?: config('app.timezone', 'UTC'),
            ],
            'generated_at' => now('UTC')->toIso8601String(),
            'filters' => $filters,
            'rows' => $fixLog->presentRows($rows),
        ]);
    }
}

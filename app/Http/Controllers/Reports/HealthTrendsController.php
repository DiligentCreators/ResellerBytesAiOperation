<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Health\HealthHistoryService;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HealthTrendsController extends Controller
{
    public function __invoke(
        Request $request,
        HealthHistoryService $history,
        TenantRoleAuthorization $authorization,
    ): Response {
        /** @var User $user */
        $user = Auth::user();
        $tenant = $this->resolveTenant($request, $user, $authorization);

        $requested = (int) $request->integer('days', 30);
        $series = $history->trendSeries($tenant, $requested);

        return Inertia::render('reports/trends', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'range_days' => $series['range_days'],
            'max_history_days' => $series['max_history_days'],
            'available_ranges' => array_values(array_filter(
                [30, 90, 365],
                static fn (int $days): bool => $days <= $series['max_history_days'],
            )),
            'points' => $series['points'],
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

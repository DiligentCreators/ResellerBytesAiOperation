<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantReportSend;
use App\Models\User;
use App\Services\Reports\MonthlyValueReportService;
use App\Services\Reports\WeeklyHealthReportService;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportResendController extends Controller
{
    public function __invoke(
        Request $request,
        Tenant $tenant,
        TenantReportSend $report,
        TenantRoleAuthorization $authorization,
        WeeklyHealthReportService $weekly,
        MonthlyValueReportService $monthly,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        abort_unless($report->tenant_id === $tenant->id, 404);
        $authorization->require($user, $tenant->id, 'tenant.read');

        $email = $user->email;
        abort_unless(is_string($email) && $email !== '', 422);

        if ($report->type === TenantReportSend::TYPE_MONTHLY_VALUE) {
            $monthly->resendTo($report, $email);
        } else {
            $weekly->resendTo($report, $email);
        }

        return back()->with('status', 'Report resent to your email.');
    }
}

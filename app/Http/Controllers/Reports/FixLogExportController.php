<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reports\FixLogQuery;
use App\Services\Tenancy\TenantRoleAuthorization;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FixLogExportController extends Controller
{
    public function __invoke(
        Request $request,
        Tenant $tenant,
        FixLogQuery $fixLog,
        TenantRoleAuthorization $authorization,
    ): StreamedResponse {
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

        $filename = 'fix-log-'.$tenant->slug.'-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($tenant, $filters, $fixLog): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, $fixLog->csvHeaders());

            $fixLog->baseQuery($tenant, $filters)
                ->chunk(200, function ($executions) use ($output, $fixLog, $tenant): void {
                    foreach ($executions as $execution) {
                        fputcsv($output, $fixLog->csvRow($execution, $tenant));
                    }
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}

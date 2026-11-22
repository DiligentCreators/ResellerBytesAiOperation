<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Reports\MonthlyValueReportService;
use Illuminate\Console\Command;

class SendMonthlyValueReportCommand extends Command
{
    protected $signature = 'reports:send-value-report
                            {--tenant= : Limit to a single active tenant UUID}
                            {--force : Bypass renewal-window and idempotency checks for this run}';

    protected $description = 'Email entitled workspaces a monthly value report N days before renewal';

    public function handle(MonthlyValueReportService $reports): int
    {
        $tenantId = $this->option('tenant');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
        $force = (bool) $this->option('force');

        if ($tenantId !== null) {
            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->where('status', Tenant::STATUS_ACTIVE)
                ->first();

            if ($tenant === null) {
                $this->error('No active tenant matched --tenant='.$tenantId);

                return self::FAILURE;
            }
        }

        $result = $reports->send(null, $tenantId, $force);

        $this->info(sprintf(
            'Monthly value report sent to %d recipient(s) across %d tenant(s) (skipped entitlement=%d window=%d idempotent=%d).',
            $result['recipients'],
            $result['tenants'],
            $result['skipped_entitlement'],
            $result['skipped_window'],
            $result['skipped_idempotent'],
        ));

        return self::SUCCESS;
    }
}

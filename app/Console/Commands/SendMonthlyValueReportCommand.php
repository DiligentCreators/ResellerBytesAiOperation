<?php

namespace App\Console\Commands;

use App\Services\Reports\MonthlyValueReportService;
use Illuminate\Console\Command;

class SendMonthlyValueReportCommand extends Command
{
    protected $signature = 'reports:send-value-report';

    protected $description = 'Email entitled workspaces a monthly value report N days before renewal';

    public function handle(MonthlyValueReportService $reports): int
    {
        $result = $reports->send();

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

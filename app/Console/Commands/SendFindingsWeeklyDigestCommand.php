<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\FindingsWeeklyDigestService;
use Illuminate\Console\Command;

class SendFindingsWeeklyDigestCommand extends Command
{
    protected $signature = 'findings:send-weekly-digest';

    protected $description = 'Email each due active tenant a weekly WHMCS health report via platform mail';

    public function handle(FindingsWeeklyDigestService $digest): int
    {
        $result = $digest->send();

        $this->info(sprintf(
            'Weekly health report sent to %d recipient(s) across %d tenant(s) (clean=%d schedule=%d idempotent=%d).',
            $result['recipients'],
            $result['tenants'],
            $result['skipped_clean'],
            $result['skipped_schedule'] ?? 0,
            $result['skipped_idempotent'] ?? 0,
        ));

        return self::SUCCESS;
    }
}

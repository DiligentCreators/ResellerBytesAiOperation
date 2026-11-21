<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\FindingsWeeklyDigestService;
use Illuminate\Console\Command;

class SendFindingsWeeklyDigestCommand extends Command
{
    protected $signature = 'findings:send-weekly-digest';

    protected $description = 'Email each active tenant a weekly WHMCS findings digest via platform mail';

    public function handle(FindingsWeeklyDigestService $digest): int
    {
        $result = $digest->send();

        $this->info(sprintf(
            'Weekly findings digest sent to %d recipient(s) across %d tenant(s) (%d clean).',
            $result['recipients'],
            $result['tenants'],
            $result['skipped_clean'],
        ));

        return self::SUCCESS;
    }
}

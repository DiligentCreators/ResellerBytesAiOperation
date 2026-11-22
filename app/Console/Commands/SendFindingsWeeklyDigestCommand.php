<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Diagnostics\FindingsWeeklyDigestService;
use Illuminate\Console\Command;

class SendFindingsWeeklyDigestCommand extends Command
{
    protected $signature = 'findings:send-weekly-digest
                            {--tenant= : Limit to a single active tenant UUID}
                            {--force : Bypass schedule/due and idempotency checks for this run}';

    protected $description = 'Email each due active tenant a weekly WHMCS health report via platform mail';

    public function handle(FindingsWeeklyDigestService $digest): int
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

        $result = $digest->send(null, $tenantId, $force);

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

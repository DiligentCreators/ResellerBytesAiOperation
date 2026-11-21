<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionManagementService;
use Illuminate\Console\Command;

class ApplyScheduledPlanChangesCommand extends Command
{
    protected $signature = 'billing:apply-scheduled-plan-changes {--limit=100}';

    protected $description = 'Apply due scheduled subscription plan changes (downgrades / yearly→monthly) at period end';

    public function handle(SubscriptionManagementService $subscriptions): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The limit must be between 1 and 500.');

            return self::FAILURE;
        }

        $result = $subscriptions->applyDueScheduledChanges($limit);

        $this->info(sprintf(
            'Scheduled plan changes: applied=%d skipped_cancel_at_period_end=%d skipped_not_due=%d',
            $result['applied'],
            $result['skipped_cancel_at_period_end'],
            $result['skipped_not_due'],
        ));

        return self::SUCCESS;
    }
}

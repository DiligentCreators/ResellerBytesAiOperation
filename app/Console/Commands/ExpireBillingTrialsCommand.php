<?php

namespace App\Console\Commands;

use App\Models\TenantSubscription;
use App\Services\Tenancy\TenantSubscriptionLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('billing:expire-trials {--limit=100 : Max trialing subscriptions to process}')]
#[Description('Expire local trialing subscriptions whose trial_ends_at has passed')]
class ExpireBillingTrialsCommand extends Command
{
    public function handle(TenantSubscriptionLifecycle $lifecycle): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $now = CarbonImmutable::now('UTC');
        $expired = 0;

        $subscriptions = TenantSubscription::query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                $lifecycle->transition(
                    $subscription->tenant_id,
                    (int) $subscription->id,
                    'expired',
                );
                $expired++;
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Failed to expire subscription '.$subscription->id.': '.$exception->getMessage());
            }
        }

        $this->info("Expired {$expired} trial subscription(s).");

        return self::SUCCESS;
    }
}

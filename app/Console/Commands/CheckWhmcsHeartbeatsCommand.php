<?php

namespace App\Console\Commands;

use App\Services\Monitoring\HeartbeatStateService;
use Illuminate\Console\Command;

class CheckWhmcsHeartbeatsCommand extends Command
{
    protected $signature = 'whmcs:heartbeats:check';

    protected $description = 'Mark WHMCS integrations stale or down when signed heartbeats stop arriving';

    public function handle(HeartbeatStateService $heartbeats): int
    {
        $result = $heartbeats->checkMissedHeartbeats();

        $this->info(sprintf(
            'Checked %d integration(s); updated %d heartbeat state(s); %d failed.',
            $result['checked'],
            $result['changed'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

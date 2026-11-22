<?php

namespace App\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstallationHeartbeatStateChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  'unknown'|'ok'|'stale'|'down'  $previousState
     * @param  'unknown'|'ok'|'stale'|'down'  $newState
     */
    public function __construct(
        public readonly string $integrationId,
        public readonly ?string $tenantId,
        public readonly string $previousState,
        public readonly string $newState,
        public readonly CarbonInterface $changedAt,
    ) {}
}

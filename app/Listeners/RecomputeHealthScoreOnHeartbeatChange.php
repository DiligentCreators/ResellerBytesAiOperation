<?php

namespace App\Listeners;

use App\Events\InstallationHeartbeatStateChanged;
use App\Services\Health\HealthSnapshotRecorder;

class RecomputeHealthScoreOnHeartbeatChange
{
    public function __construct(
        private readonly HealthSnapshotRecorder $recorder,
    ) {}

    public function handle(InstallationHeartbeatStateChanged $event): void
    {
        $this->recorder->recomputeForIntegrationId($event->integrationId);
    }
}

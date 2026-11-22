<?php

namespace App\Services\Alerts;

use App\Events\CriticalFindingRaised;
use App\Models\DiagnosticFinding;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether a newly persisted critical finding should raise a workspace alert.
 */
final class CriticalFindingAlertGate
{
    public function maybeRaise(DiagnosticFinding $finding): void
    {
        if ($finding->severity !== 'critical' || $finding->tenant_id === null) {
            return;
        }

        if (! $this->isNewOrWorsened($finding)) {
            return;
        }

        event(new CriticalFindingRaised($finding->id, $finding->tenant_id));
    }

    private function isNewOrWorsened(DiagnosticFinding $finding): bool
    {
        $finding->loadMissing('diagnosticRun');
        $integrationId = $finding->diagnosticRun?->integration_id;
        if ($integrationId === null) {
            return true;
        }

        $priorActive = DiagnosticFinding::query()
            ->where('fingerprint', $finding->fingerprint)
            ->whereKeyNot($finding->id)
            ->active()
            ->whereHas('diagnosticRun', function (Builder $query) use ($integrationId): void {
                $query->where('integration_id', $integrationId);
            })
            ->latest('created_at')
            ->first();

        if ($priorActive === null) {
            return true;
        }

        return $priorActive->severity !== 'critical';
    }
}

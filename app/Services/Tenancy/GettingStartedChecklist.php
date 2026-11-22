<?php

namespace App\Services\Tenancy;

use App\Models\DiagnosticFinding;
use App\Models\GuidedFixStep;
use App\Models\SafeActionExecution;
use App\Models\Tenant;
use App\Models\TenantAlertChannel;
use App\Models\TenantMembership;
use App\Models\User;

class GettingStartedChecklist
{
    /**
     * Post-onboarding checklist shown on Home until complete or dismissed.
     *
     * @return array{
     *     visible: bool,
     *     dismissed: bool,
     *     completed: bool,
     *     tenant_id: string,
     *     items: array{
     *         alert_channel: bool,
     *         first_fix: bool,
     *         invite_teammate: bool
     *     }
     * }|null
     */
    public function forOwner(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $membership = $user->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->where('role', TenantMembership::ROLE_OWNER)
            ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))
            ->with('tenant')
            ->orderBy('created_at')
            ->first();

        if ($membership === null) {
            return null;
        }

        /** @var Tenant $tenant */
        $tenant = $membership->tenant;

        if ($tenant->onboarding_completed_at === null) {
            return null;
        }

        $items = $this->deriveItems($tenant);
        $dismissed = filled(data_get($tenant->settings, 'getting_started.dismissed_at'));
        $completed = $items['alert_channel'] && $items['first_fix'] && $items['invite_teammate'];

        return [
            'visible' => ! $dismissed && ! $completed,
            'dismissed' => $dismissed,
            'completed' => $completed,
            'tenant_id' => (string) $tenant->id,
            'items' => $items,
        ];
    }

    /**
     * @return array{alert_channel: bool, first_fix: bool, invite_teammate: bool}
     */
    public function deriveItems(Tenant $tenant): array
    {
        $hasAlertChannel = TenantAlertChannel::query()
            ->where('tenant_id', $tenant->id)
            ->exists();

        $hasSafeAction = SafeActionExecution::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                SafeActionExecution::STATUS_COMPLETED,
                SafeActionExecution::STATUS_VERIFIED,
            ])
            ->exists();

        $hasGuidedFix = GuidedFixStep::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('completed_at')
            ->exists();

        $hasResolvedFinding = DiagnosticFinding::query()
            ->where('tenant_id', $tenant->id)
            ->notMerged()
            ->whereIn('status', [
                DiagnosticFinding::STATUS_RESOLVED,
                DiagnosticFinding::STATUS_ACCEPTED_RISK,
            ])
            ->exists();

        $hasTeammate = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', '!=', $tenant->created_by_user_id)
            ->whereIn('status', [
                TenantMembership::STATUS_ACTIVE,
                TenantMembership::STATUS_INVITED,
            ])
            ->exists();

        return [
            'alert_channel' => $hasAlertChannel,
            'first_fix' => $hasSafeAction || $hasGuidedFix || $hasResolvedFinding,
            'invite_teammate' => $hasTeammate,
        ];
    }

    public function dismiss(Tenant $tenant): void
    {
        $settings = (array) $tenant->settings;
        $gettingStarted = (array) ($settings['getting_started'] ?? []);
        $gettingStarted['dismissed_at'] = now()->toIso8601String();
        $settings['getting_started'] = $gettingStarted;
        $tenant->forceFill(['settings' => $settings])->save();
    }
}

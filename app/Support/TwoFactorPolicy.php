<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use App\Services\PlatformSettings;

/**
 * Single place that decides when the app forces two-factor enrollment.
 *
 * Platform setting security.two_factor_required (default false) gates all forced redirects.
 * Users who already enabled 2FA are still challenged at login by Fortify regardless.
 */
final class TwoFactorPolicy
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly CentralStaffAuthorization $authorization,
    ) {}

    public function isPlatformRequirementEnabled(): bool
    {
        return (bool) $this->settings->get('security.two_factor_required');
    }

    public function isRequiredFor(User $user): bool
    {
        if (! $this->isPlatformRequirementEnabled()) {
            return false;
        }

        if ($this->authorization->isPlatformOperator($user)) {
            return true;
        }

        return $user->tenantMemberships()
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('tenant', function ($query): void {
                $query->where('status', Tenant::STATUS_ACTIVE)
                    ->where('require_two_factor', true);
            })
            ->exists();
    }
}

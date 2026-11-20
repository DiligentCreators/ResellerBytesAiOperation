<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\CentralStaffAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnabled
{
    /**
     * Require confirmed two-factor authentication for central/platform staff, and for
     * members of workspaces whose owner has enabled require_two_factor.
     *
     * Self-serve customers without those obligations are not blocked; the UI encourages 2FA.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($this->mustEnforceTwoFactor($user)) {
            return redirect()
                ->route('security.edit')
                ->with('status', 'Enable and confirm two-factor authentication before continuing.');
        }

        return $next($request);
    }

    private function mustEnforceTwoFactor(User $user): bool
    {
        $authorization = app(CentralStaffAuthorization::class);

        if ($authorization->isPlatformOperator($user)) {
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

<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TwoFactorPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnabled
{
    /**
     * When platform security.two_factor_required is on: require confirmed 2FA for
     * central/platform staff and for members of workspaces with require_two_factor.
     * When off: never redirect anyone for missing 2FA (self-enrolled users still
     * face Fortify's login challenge).
     */
    public function __construct(private readonly TwoFactorPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($this->policy->isRequiredFor($user)) {
            return redirect()
                ->route('security.edit')
                ->with('status', 'Enable and confirm two-factor authentication before continuing.');
        }

        return $next($request);
    }
}

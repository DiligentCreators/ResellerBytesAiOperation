<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnabled
{
    /**
     * Require confirmed two-factor authentication before operational access.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()
                ->route('security.edit')
                ->with('status', 'Enable and confirm two-factor authentication before accessing the operations dashboard.');
        }

        return $next($request);
    }
}

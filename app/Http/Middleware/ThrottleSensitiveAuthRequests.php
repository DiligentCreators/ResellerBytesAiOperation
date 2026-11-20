<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify password-reset POSTs are unthrottled by default; apply named limiters by route.
 */
class ThrottleSensitiveAuthRequests
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = match (true) {
            $request->routeIs('password.email', 'password.update') => 'password-reset',
            $request->routeIs('verification.send') => 'verification',
            default => null,
        };

        if ($limiter === null) {
            return $next($request);
        }

        return app(ThrottleRequests::class)->handle($request, $next, $limiter);
    }
}

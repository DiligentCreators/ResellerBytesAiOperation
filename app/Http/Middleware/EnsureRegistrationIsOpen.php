<?php

namespace App\Http\Middleware;

use App\Support\RegistrationPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks public Fortify registration when security.registration_open is off.
 * Only applies to register / register.store; other auth flows are untouched.
 */
class EnsureRegistrationIsOpen
{
    public function __construct(private readonly RegistrationPolicy $registration) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('register', 'register.store')) {
            return $next($request);
        }

        if ($this->registration->isOpen()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, RegistrationPolicy::CLOSED_MESSAGE);
        }

        return redirect()
            ->route('login')
            ->with('status', RegistrationPolicy::CLOSED_MESSAGE);
    }
}

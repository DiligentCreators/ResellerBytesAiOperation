<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Apply baseline browser security headers suitable for Inertia + Vite.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        // Inline script/style in app.blade.php boot theme before CSS/JS load.
        // Vite HMR needs ws:/http: localhost in non-production; built assets are same-origin.
        $connect = "'self'";
        $script = "'self' 'unsafe-inline'";
        $style = "'self' 'unsafe-inline'";

        if (! app()->isProduction()) {
            $connect .= ' http://localhost:* http://127.0.0.1:* ws://localhost:* ws://127.0.0.1:*';
            $script .= ' http://localhost:* http://127.0.0.1:*';
            $style .= ' http://localhost:* http://127.0.0.1:*';
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "script-src {$script}",
            "style-src {$style}",
            "connect-src {$connect}",
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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

        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        }

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
        // Vite HMR needs the hot origin in non-production; built assets are same-origin.
        $connect = "'self'";
        $script = "'self' 'unsafe-inline'";
        $style = "'self' 'unsafe-inline'";
        $font = "'self' data:";
        $img = "'self' data: blob:";

        if (! app()->isProduction()) {
            $hotOrigin = $this->viteHotOrigin();

            if ($hotOrigin !== null) {
                if ($this->hotOriginHostIsCspExpressible($hotOrigin)) {
                    $wsOrigin = preg_replace('#^http#', 'ws', $hotOrigin) ?? $hotOrigin;
                    $script .= " {$hotOrigin}";
                    $style .= " {$hotOrigin}";
                    $font .= " {$hotOrigin}";
                    $img .= " {$hotOrigin}";
                    $connect .= " {$hotOrigin} {$wsOrigin}";
                } else {
                    // Browsers reject IPv6 literal host-sources (e.g. http://[::1]:5173) and
                    // ignore them, which blanks Inertia under Herd's Vite hot file. Prefer
                    // scheme sources over dropping CSP entirely so other directives still apply.
                    $script .= ' http:';
                    $style .= ' http:';
                    $font .= ' http:';
                    $img .= ' http:';
                    $connect .= ' http: ws:';
                }
            } else {
                $connect .= ' http://localhost:* http://127.0.0.1:* ws://localhost:* ws://127.0.0.1:*';
                $script .= ' http://localhost:* http://127.0.0.1:*';
                $style .= ' http://localhost:* http://127.0.0.1:*';
            }
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src {$img}",
            "font-src {$font}",
            "script-src {$script}",
            "style-src {$style}",
            "connect-src {$connect}",
        ]);
    }

    /**
     * Resolve the Vite HMR origin from public/hot (e.g. http://[::1]:5173).
     */
    private function viteHotOrigin(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $hotFile = Vite::hotFile();
        if (! is_string($hotFile) || ! is_file($hotFile)) {
            return null;
        }

        $raw = trim((string) file_get_contents($hotFile));
        if ($raw === '' || ! filter_var($raw, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($raw);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $host = (string) $parts['host'];
        $origin = $parts['scheme'].'://'.$host;
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    /**
     * CSP Level 3 host-sources cannot express IPv6 literals; browsers ignore them.
     */
    private function hotOriginHostIsCspExpressible(string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $normalized = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        return filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false;
    }
}

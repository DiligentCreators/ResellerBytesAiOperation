<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantDatabaseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');
        $tenantId = is_object($tenant) && isset($tenant->id) ? (string) $tenant->id : (is_string($tenant) ? $tenant : null);
        $userId = $request->user()?->getAuthIdentifier();

        return app(TenantDatabaseContext::class)->run(
            $tenantId,
            $userId === null ? null : (int) $userId,
            fn (): Response => $next($request),
        );
    }
}

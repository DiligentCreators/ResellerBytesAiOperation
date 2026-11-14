<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetTenantDatabaseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');
        $tenantId = is_object($tenant) && isset($tenant->id) ? (string) $tenant->id : (is_string($tenant) ? $tenant : null);
        $userId = $request->user()?->getAuthIdentifier();
        $userId = $userId === null ? null : (int) $userId;

        return app(TenantDatabaseContext::class)->run(null, $userId, function () use ($request, $next, $tenantId, $userId): Response {
            if ($tenantId !== null && $userId !== null) {
                $isActiveMember = DB::table('tenant_memberships')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->exists();

                if ($isActiveMember) {
                    return app(TenantDatabaseContext::class)->run(
                        $tenantId,
                        $userId,
                        fn (): Response => $next($request),
                    );
                }
            }

            return $next($request);
        });
    }
}

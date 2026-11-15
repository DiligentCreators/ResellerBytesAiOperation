<?php

namespace App\Http\Middleware;

use App\Services\CentralStaffAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCentralPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), $permission), 403);

        return $next($request);
    }
}

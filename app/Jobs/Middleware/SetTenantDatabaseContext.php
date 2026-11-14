<?php

namespace App\Jobs\Middleware;

use App\Services\Tenancy\TenantDatabaseContext;
use Closure;

class SetTenantDatabaseContext
{
    public function __construct(private readonly ?string $tenantId, private readonly ?int $userId = null) {}

    public function handle(object $job, Closure $next): mixed
    {
        return app(TenantDatabaseContext::class)->run(
            $this->tenantId,
            $this->userId,
            fn () => $next($job),
        );
    }
}

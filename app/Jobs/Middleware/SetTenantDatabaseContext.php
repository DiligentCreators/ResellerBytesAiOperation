<?php

namespace App\Jobs\Middleware;

use App\Models\TenantMembership;
use App\Services\Tenancy\TenantDatabaseContext;
use Closure;

class SetTenantDatabaseContext
{
    public function __construct(private readonly ?string $tenantId, private readonly ?int $userId = null) {}

    public function handle(object $job, Closure $next): mixed
    {
        return app(TenantDatabaseContext::class)->run(null, $this->userId, function () use ($job, $next): mixed {
            if ($this->tenantId !== null && $this->userId !== null) {
                $activeMembership = TenantMembership::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('user_id', $this->userId)
                    ->where('status', TenantMembership::STATUS_ACTIVE)
                    ->exists();

                if ($activeMembership) {
                    return app(TenantDatabaseContext::class)->run(
                        $this->tenantId,
                        $this->userId,
                        fn () => $next($job),
                    );
                }

                return null;
            }

            return $next($job);
        });
    }
}

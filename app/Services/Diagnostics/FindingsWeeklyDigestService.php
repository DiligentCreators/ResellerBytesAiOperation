<?php

namespace App\Services\Diagnostics;

use App\Models\Tenant;
use App\Services\Reports\WeeklyHealthReportService;
use Carbon\CarbonInterface;

/**
 * Backwards-compatible facade for findings:send-weekly-digest.
 * Weekly health report logic lives in WeeklyHealthReportService.
 */
class FindingsWeeklyDigestService
{
    public function __construct(
        private readonly WeeklyHealthReportService $weeklyReports,
    ) {}

    /**
     * @return array{tenants: int, recipients: int, skipped_clean: int, skipped_schedule: int, skipped_idempotent: int}
     */
    public function send(?CarbonInterface $since = null, ?string $tenantId = null, bool $force = false): array
    {
        // $since is retained for BC with callers; schedule/idempotency drive the real period.
        unset($since);

        return $this->weeklyReports->send(null, $tenantId, $force);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDigest(Tenant $tenant, CarbonInterface $since): array
    {
        $periodEnd = now('UTC');
        $periodStart = $since->copy()->utc();

        return $this->weeklyReports->buildDigest($tenant, $periodStart, $periodEnd);
    }
}

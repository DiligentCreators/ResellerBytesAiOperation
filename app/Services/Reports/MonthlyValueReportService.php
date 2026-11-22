<?php

namespace App\Services\Reports;

use App\Mail\MonthlyValueReportMail;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantReportSend;
use App\Models\TenantSubscription;
use App\Services\Mail\CentralOutboundMailer;
use App\Services\PlatformSettings;
use App\Services\Tenancy\TenantEntitlementService;
use App\Support\Reports\MonthlyValueReportPeriodLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MonthlyValueReportService
{
    public function __construct(
        private readonly CentralOutboundMailer $mailer,
        private readonly PlatformSettings $platformSettings,
        private readonly ReportMetricsService $metrics,
        private readonly TenantEntitlementService $entitlements,
        private readonly WeeklyHealthReportService $weeklyReports,
    ) {}

    /**
     * @return array{tenants: int, recipients: int, skipped_entitlement: int, skipped_window: int, skipped_idempotent: int}
     */
    public function send(?CarbonInterface $now = null, ?string $tenantId = null, bool $force = false): array
    {
        $now = CarbonImmutable::instance(($now ?? now('UTC'))->utc());
        $daysBefore = $this->daysBeforeRenewal();
        $tenants = 0;
        $recipients = 0;
        $skippedEntitlement = 0;
        $skippedWindow = 0;
        $skippedIdempotent = 0;

        $query = Tenant::query()->where('status', Tenant::STATUS_ACTIVE);
        if ($tenantId !== null) {
            $query->whereKey($tenantId);
        }

        $query
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use ($now, $daysBefore, $force, &$tenants, &$recipients, &$skippedEntitlement, &$skippedWindow, &$skippedIdempotent): void {
                foreach ($chunk as $tenant) {
                    $tenants++;

                    if (! $this->hasValueEntitlement($tenant->id)) {
                        $skippedEntitlement++;

                        continue;
                    }

                    $subscription = $this->activeSubscription($tenant->id);
                    if ($subscription === null || $subscription->current_period_ends_at === null) {
                        $skippedWindow++;

                        continue;
                    }

                    if (! $force && ! $this->isWithinSendWindow($tenant, $subscription, $now, $daysBefore)) {
                        $skippedWindow++;

                        continue;
                    }

                    // Metrics cover the last 30 days ending on the send day — not the
                    // subscription term (yearly plans would otherwise show a year-long header).
                    $period = $this->metricsPeriodFor($now);
                    $periodStart = $period['start'];
                    $periodEnd = $period['end'];

                    if (! $force && $this->alreadySent($tenant->id, $periodStart)) {
                        $skippedIdempotent++;

                        continue;
                    }

                    if ($force) {
                        $this->forgetSendRecord($tenant->id, $periodStart);
                    }

                    $summary = $this->metrics->build($tenant, $periodStart, $periodEnd);
                    $summary['renewal_at'] = $subscription->current_period_ends_at->toIso8601String();
                    $summary['days_before_renewal'] = $daysBefore;
                    $summary['period_label'] = $this->periodLabel($periodStart, $periodEnd);

                    $recipients += $this->deliverAndRecord($tenant, $periodStart, $periodEnd, $summary);
                }
            });

        return [
            'tenants' => $tenants,
            'recipients' => $recipients,
            'skipped_entitlement' => $skippedEntitlement,
            'skipped_window' => $skippedWindow,
            'skipped_idempotent' => $skippedIdempotent,
        ];
    }

    public function daysBeforeRenewal(): int
    {
        $days = $this->platformSettings->get('reports.value_report_days_before_renewal');

        return max(0, min(60, is_numeric($days) ? (int) $days : 3));
    }

    /**
     * Rolling 30-day window ending on the send day's UTC date.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function metricsPeriodFor(CarbonInterface $now): array
    {
        $end = CarbonImmutable::instance($now)->utc()->startOfDay();

        return [
            'start' => $end->subDays(30),
            'end' => $end,
        ];
    }

    public function periodLabel(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return MonthlyValueReportPeriodLabel::lastThirtyDays($periodStart, $periodEnd);
    }

    public function hasValueEntitlement(string $tenantId): bool
    {
        try {
            $this->entitlements->requireFeature($tenantId, 'reports.value');

            return true;
        } catch (AuthorizationException|ModelNotFoundException) {
            return false;
        }
    }

    public function isWithinSendWindow(
        Tenant $tenant,
        TenantSubscription $subscription,
        CarbonInterface $now,
        ?int $daysBefore = null,
    ): bool {
        $daysBefore ??= $this->daysBeforeRenewal();
        $timezone = $this->weeklyReports->timezoneFor($tenant);
        $localNow = CarbonImmutable::instance($now)->timezone($timezone)->startOfDay();
        $renewalLocal = CarbonImmutable::instance($subscription->current_period_ends_at)
            ->timezone($timezone)
            ->startOfDay();
        $target = $renewalLocal->subDays($daysBefore);

        return $localNow->equalTo($target);
    }

    /**
     * Build the monthly value report mail from the stored report row (same period
     * label resolution as ReportShowController).
     */
    public function mailableFor(TenantReportSend $report): MonthlyValueReportMail
    {
        $summary = is_array($report->summary) ? $report->summary : [];
        $periodLabel = MonthlyValueReportPeriodLabel::resolve(
            $summary,
            $report->period_start?->toDateString(),
            $report->period_end?->toDateString(),
        );

        return new MonthlyValueReportMail($summary, $periodLabel);
    }

    /**
     * @return array{sent: bool}
     */
    public function resendTo(TenantReportSend $report, string $email): array
    {
        $mailable = $this->mailableFor($report);
        $html = $mailable->render();
        $this->mailer->send(
            $email,
            $mailable->envelope()->subject,
            $html,
            strip_tags(str_replace(['</p>', '</li>', '</h1>', '</h2>'], ["\n", "\n", "\n", "\n"], $html)),
        );

        return ['sent' => true];
    }

    private function activeSubscription(string $tenantId): ?TenantSubscription
    {
        try {
            return $this->entitlements->activeSubscription($tenantId);
        } catch (AuthorizationException|ModelNotFoundException) {
            return null;
        }
    }

    private function alreadySent(string $tenantId, CarbonInterface $periodStart): bool
    {
        return TenantReportSend::query()
            ->where('tenant_id', $tenantId)
            ->where('type', TenantReportSend::TYPE_MONTHLY_VALUE)
            ->whereDate('period_start', $periodStart->toDateString())
            ->exists();
    }

    private function forgetSendRecord(string $tenantId, CarbonInterface $periodStart): void
    {
        TenantReportSend::query()
            ->where('tenant_id', $tenantId)
            ->where('type', TenantReportSend::TYPE_MONTHLY_VALUE)
            ->whereDate('period_start', $periodStart->toDateString())
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function deliverAndRecord(Tenant $tenant, CarbonInterface $periodStart, CarbonInterface $periodEnd, array $summary): int
    {
        $recipients = 0;

        try {
            return (int) DB::transaction(function () use ($tenant, $periodStart, $periodEnd, $summary, &$recipients) {
                $record = TenantReportSend::query()->create([
                    'tenant_id' => $tenant->id,
                    'type' => TenantReportSend::TYPE_MONTHLY_VALUE,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'sent_at' => null,
                    'summary' => $summary,
                ]);

                $members = TenantMembership::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', TenantMembership::STATUS_ACTIVE)
                    ->whereIn('role', [
                        TenantMembership::ROLE_OWNER,
                        TenantMembership::ROLE_ADMIN,
                        TenantMembership::ROLE_BILLING,
                    ])
                    ->with('user:id,name,email')
                    ->get();

                $periodLabel = is_string($summary['period_label'] ?? null) && $summary['period_label'] !== ''
                    ? $summary['period_label']
                    : $this->periodLabel($periodStart, $periodEnd);
                $mailable = new MonthlyValueReportMail($summary, $periodLabel);

                foreach ($members as $membership) {
                    $email = $membership->user?->email;
                    if (! is_string($email) || $email === '') {
                        continue;
                    }

                    $html = $mailable->render();
                    $this->mailer->send(
                        $email,
                        $mailable->envelope()->subject,
                        $html,
                        strip_tags(str_replace(['</p>', '</li>', '</h1>', '</h2>'], ["\n", "\n", "\n", "\n"], $html)),
                    );
                    $recipients++;
                }

                $record->forceFill(['sent_at' => now('UTC')])->save();

                return $recipients;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return 0;
            }

            throw $exception;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23505'
            || $driverCode === 19
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}

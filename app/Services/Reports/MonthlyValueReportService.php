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
    public function send(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance(($now ?? now('UTC'))->utc());
        $daysBefore = $this->daysBeforeRenewal();
        $tenants = 0;
        $recipients = 0;
        $skippedEntitlement = 0;
        $skippedWindow = 0;
        $skippedIdempotent = 0;

        Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use ($now, $daysBefore, &$tenants, &$recipients, &$skippedEntitlement, &$skippedWindow, &$skippedIdempotent): void {
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

                    if (! $this->isWithinSendWindow($tenant, $subscription, $now, $daysBefore)) {
                        $skippedWindow++;

                        continue;
                    }

                    $periodStart = CarbonImmutable::instance($subscription->current_period_starts_at ?? $now)->utc()->startOfDay();
                    $periodEnd = CarbonImmutable::instance($subscription->current_period_ends_at)->utc()->startOfDay();

                    if ($this->alreadySent($tenant->id, $periodStart)) {
                        $skippedIdempotent++;

                        continue;
                    }

                    $summary = $this->metrics->build($tenant, $periodStart, $now);
                    $summary['renewal_at'] = $subscription->current_period_ends_at->toIso8601String();
                    $summary['days_before_renewal'] = $daysBefore;

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
     * @return array{sent: bool}
     */
    public function resendTo(TenantReportSend $report, string $email): array
    {
        $summary = is_array($report->summary) ? $report->summary : [];
        $mailable = new MonthlyValueReportMail($summary);
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

                $mailable = new MonthlyValueReportMail($summary);

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

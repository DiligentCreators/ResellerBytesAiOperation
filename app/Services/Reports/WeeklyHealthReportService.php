<?php

namespace App\Services\Reports;

use App\Mail\FindingsWeeklyDigestMail;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantReportSend;
use App\Services\Mail\CentralOutboundMailer;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class WeeklyHealthReportService
{
    public function __construct(
        private readonly CentralOutboundMailer $mailer,
        private readonly PlatformSettings $platformSettings,
        private readonly ReportMetricsService $metrics,
    ) {}

    /**
     * @return array{tenants: int, recipients: int, skipped_clean: int, skipped_schedule: int, skipped_idempotent: int}
     */
    public function send(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance(($now ?? now('UTC'))->utc());
        $tenants = 0;
        $recipients = 0;
        $skippedClean = 0;
        $skippedSchedule = 0;
        $skippedIdempotent = 0;

        Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use ($now, &$tenants, &$recipients, &$skippedClean, &$skippedSchedule, &$skippedIdempotent): void {
                foreach ($chunk as $tenant) {
                    $tenants++;

                    if (! $this->isDue($tenant, $now)) {
                        $skippedSchedule++;

                        continue;
                    }

                    $period = $this->periodFor($tenant, $now);
                    if ($this->alreadySent($tenant->id, $period['start'])) {
                        $skippedIdempotent++;

                        continue;
                    }

                    $digest = $this->buildDigest($tenant, $period['start'], $period['end']);

                    if ($digest['open'] === 0
                        && $digest['in_progress'] === 0
                        && $digest['resolved_this_week'] === 0
                        && $digest['awaiting_safe_actions'] === 0) {
                        $skippedClean++;
                    }

                    $sent = $this->deliverAndRecord($tenant, $period['start'], $period['end'], $digest);
                    $recipients += $sent;
                }
            });

        return [
            'tenants' => $tenants,
            'recipients' => $recipients,
            'skipped_clean' => $skippedClean,
            'skipped_schedule' => $skippedSchedule,
            'skipped_idempotent' => $skippedIdempotent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDigest(Tenant $tenant, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return $this->metrics->build($tenant, $periodStart, $periodEnd);
    }

    public function isDue(Tenant $tenant, CarbonInterface $now): bool
    {
        $timezone = $this->timezoneFor($tenant);
        $local = CarbonImmutable::instance($now)->timezone($timezone);
        $weeklyDay = $this->weeklyDay();
        $weeklyTime = $this->weeklyTime();

        if ((int) $local->isoWeekday() !== $weeklyDay) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', $weeklyTime));
        $scheduled = $local->startOfDay()->setTime($hour, $minute);

        return $local->greaterThanOrEqualTo($scheduled);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function periodFor(Tenant $tenant, CarbonInterface $now): array
    {
        $timezone = $this->timezoneFor($tenant);
        $local = CarbonImmutable::instance($now)->timezone($timezone);
        $end = $local->startOfDay();
        $start = $end->subDays(6);

        return ['start' => $start, 'end' => $end];
    }

    public function timezoneFor(Tenant $tenant): string
    {
        $timezone = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : (string) config('app.timezone', 'UTC');

        try {
            CarbonImmutable::now($timezone);

            return $timezone;
        } catch (Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    public function weeklyDay(): int
    {
        $day = $this->platformSettings->get('reports.weekly_day');

        return max(1, min(7, is_numeric($day) ? (int) $day : 1));
    }

    public function weeklyTime(): string
    {
        $time = $this->platformSettings->get('reports.weekly_time');
        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            return '09:00';
        }

        return $time;
    }

    private function alreadySent(string $tenantId, CarbonInterface $periodStart): bool
    {
        return TenantReportSend::query()
            ->where('tenant_id', $tenantId)
            ->where('type', TenantReportSend::TYPE_WEEKLY)
            ->whereDate('period_start', $periodStart->toDateString())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $digest
     */
    private function deliverAndRecord(Tenant $tenant, CarbonInterface $periodStart, CarbonInterface $periodEnd, array $digest): int
    {
        $recipients = 0;

        try {
            return (int) DB::transaction(function () use ($tenant, $periodStart, $periodEnd, $digest, &$recipients) {
                $record = TenantReportSend::query()->create([
                    'tenant_id' => $tenant->id,
                    'type' => TenantReportSend::TYPE_WEEKLY,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'sent_at' => null,
                    'summary' => $digest,
                ]);

                $members = TenantMembership::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', TenantMembership::STATUS_ACTIVE)
                    ->whereIn('role', [
                        TenantMembership::ROLE_OWNER,
                        TenantMembership::ROLE_ADMIN,
                        TenantMembership::ROLE_OPERATOR,
                    ])
                    ->with('user:id,name,email')
                    ->get();

                $mailable = new FindingsWeeklyDigestMail($digest);

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

    /**
     * Resend a stored weekly report summary to the given address.
     *
     * @return array{sent: bool}
     */
    public function resendTo(TenantReportSend $report, string $email): array
    {
        $digest = is_array($report->summary) ? $report->summary : [];
        $mailable = new FindingsWeeklyDigestMail($digest);
        $html = $mailable->render();
        $this->mailer->send(
            $email,
            $mailable->envelope()->subject,
            $html,
            strip_tags(str_replace(['</p>', '</li>', '</h1>', '</h2>'], ["\n", "\n", "\n", "\n"], $html)),
        );

        return ['sent' => true];
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

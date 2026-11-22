<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Display label for monthly value report periods (page payload and mail must agree).
 *
 * Prefer an explicit summary period_label written by new send code. Otherwise use
 * "Last 30 days: …" only when the stored span is at most 31 days; older year-long
 * rows get a plain start–end date range.
 */
final class MonthlyValueReportPeriodLabel
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public static function resolve(
        array $summary,
        CarbonInterface|string|null $periodStart = null,
        CarbonInterface|string|null $periodEnd = null,
    ): string {
        $explicit = $summary['period_label'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $start = $periodStart ?? ($summary['period_start'] ?? null);
        $end = $periodEnd ?? ($summary['period_end'] ?? null);

        return self::fromDates($start, $end);
    }

    public static function forDates(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): string {
        $start = CarbonImmutable::instance($periodStart)->utc()->startOfDay();
        $end = CarbonImmutable::instance($periodEnd)->utc()->startOfDay();

        if ($start->diffInDays($end) <= 31) {
            return self::lastThirtyDays($start, $end);
        }

        return self::plainRange($start, $end);
    }

    public static function lastThirtyDays(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): string {
        $start = CarbonImmutable::instance($periodStart)->utc();
        $end = CarbonImmutable::instance($periodEnd)->utc();

        if ($start->year === $end->year) {
            return sprintf(
                'Last 30 days: %s - %s',
                $start->format('M j'),
                $end->format('M j, Y'),
            );
        }

        return sprintf(
            'Last 30 days: %s - %s',
            $start->format('M j, Y'),
            $end->format('M j, Y'),
        );
    }

    private static function fromDates(mixed $start, mixed $end): string
    {
        $startAt = self::parseUtcDate($start);
        $endAt = self::parseUtcDate($end);

        if ($startAt === null || $endAt === null) {
            return 'Last 30 days';
        }

        return self::forDates($startAt, $endAt);
    }

    private static function plainRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return sprintf(
            '%s - %s',
            $start->format('M j, Y'),
            $end->format('M j, Y'),
        );
    }

    private static function parseUtcDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->utc()->startOfDay();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}

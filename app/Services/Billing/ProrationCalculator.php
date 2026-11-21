<?php

namespace App\Services\Billing;

use App\Data\Billing\ProrationBreakdown;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class ProrationCalculator
{
    /**
     * Day-based unused-time credit for an immediate upgrade.
     *
     * Remaining days are whole calendar days from the start of today (UTC) to
     * current_period_ends_at. Credit uses half-up rounding in integer minor units,
     * is floored at 0, and never exceeds the new plan price.
     */
    public function forUpgrade(
        int $newPriceMinor,
        int $currentPeriodAmountPaidMinor,
        DateTimeInterface $currentPeriodStartsAt,
        DateTimeInterface $currentPeriodEndsAt,
        string $currency,
        ?DateTimeInterface $now = null,
    ): ProrationBreakdown {
        if ($newPriceMinor < 0 || $currentPeriodAmountPaidMinor < 0) {
            throw new InvalidArgumentException('proration_amounts_invalid');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('proration_currency_invalid');
        }

        $periodStart = CarbonImmutable::instance($currentPeriodStartsAt)->utc();
        $periodEnd = CarbonImmutable::instance($currentPeriodEndsAt)->utc();
        $today = CarbonImmutable::instance($now ?? CarbonImmutable::now('UTC'))->utc()->startOfDay();

        if (! $periodEnd->greaterThan($periodStart)) {
            throw new InvalidArgumentException('proration_period_invalid');
        }

        $totalDays = max(1, (int) $periodStart->startOfDay()->diffInDays($periodEnd->startOfDay()));
        $remainingDays = (int) $today->diffInDays($periodEnd, false);
        if ($remainingDays < 0) {
            $remainingDays = 0;
        }
        if ($remainingDays > $totalDays) {
            $remainingDays = $totalDays;
        }

        $creditMinor = 0;
        if ($remainingDays > 0 && $currentPeriodAmountPaidMinor > 0 && $totalDays > 0) {
            // Half-up integer rounding: round(paid * remaining / total).
            $creditMinor = intdiv(
                ($currentPeriodAmountPaidMinor * $remainingDays * 2) + $totalDays,
                $totalDays * 2,
            );
        }

        if ($creditMinor < 0) {
            $creditMinor = 0;
        }
        if ($creditMinor > $newPriceMinor) {
            $creditMinor = $newPriceMinor;
        }

        return new ProrationBreakdown(
            newPriceMinor: $newPriceMinor,
            creditMinor: $creditMinor,
            totalDueMinor: $newPriceMinor - $creditMinor,
            remainingDays: $remainingDays,
            totalDays: $totalDays,
            currency: $currency,
        );
    }
}

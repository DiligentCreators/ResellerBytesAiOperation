<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Human-readable billing dates for customer-facing flashes and copy.
 */
final class BillingDates
{
    /**
     * Format a date as "Nov 21, 2027" in the workspace timezone when set, else UTC.
     */
    public static function humanDate(
        CarbonInterface|DateTimeInterface|string|null $value,
        ?Tenant $tenant = null,
    ): string {
        if ($value === null || $value === '') {
            return '';
        }

        $timezone = self::timezone($tenant);

        try {
            $carbon = $value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)->timezone($timezone)
                : CarbonImmutable::parse($value, 'UTC')->timezone($timezone);
        } catch (\Throwable) {
            return '';
        }

        return $carbon->format('M j, Y');
    }

    public static function timezone(?Tenant $tenant = null): string
    {
        $timezone = $tenant?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}

<?php

namespace App\Support;

final class Money
{
    /**
     * Format a money amount stored in integer minor units.
     * Negative amounts place the Unicode minus (U+2212) before the currency symbol.
     */
    public static function format(int $amountMinor, string $currency = 'USD'): string
    {
        $currency = strtoupper($currency);
        $negative = $amountMinor < 0;
        $major = number_format(abs($amountMinor) / 100, 2, '.', ',');

        $formatted = $currency === 'USD' ? '$'.$major : $currency.' '.$major;

        return $negative ? "\u{2212}".$formatted : $formatted;
    }
}

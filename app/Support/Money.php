<?php

namespace App\Support;

final class Money
{
    public static function format(int $amountMinor, string $currency = 'USD'): string
    {
        $currency = strtoupper($currency);
        $major = number_format($amountMinor / 100, 2, '.', ',');

        return $currency === 'USD' ? '$'.$major : $currency.' '.$major;
    }
}

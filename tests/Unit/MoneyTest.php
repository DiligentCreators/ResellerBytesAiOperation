<?php

use App\Support\Money;

it('formats USD amounts in major units with two decimals', function (): void {
    expect(Money::format(14900))->toBe('$149.00')
        ->and(Money::format(0))->toBe('$0.00');
});

it('places a Unicode minus before the currency symbol for credits', function (): void {
    expect(Money::format(-4900))->toBe("\u{2212}\$49.00")
        ->and(Money::format(-4900, 'usd'))->toBe("\u{2212}\$49.00");
});

it('formats non-USD currencies with a space-separated code', function (): void {
    expect(Money::format(1000, 'eur'))->toBe('EUR 10.00')
        ->and(Money::format(-250, 'GBP'))->toBe("\u{2212}GBP 2.50");
});

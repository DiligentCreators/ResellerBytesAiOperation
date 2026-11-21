<?php

use App\Services\Billing\ProrationCalculator;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 15:30:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('credits mid-period unused time with half-up rounding', function (): void {
    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 14900,
        currentPeriodAmountPaidMinor: 3100,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    // remaining days from 2026-11-21 start → 2026-12-01 = 10; total days = 30
    // credit = round(3100 * 10 / 30) = round(1033.333) = 1033
    expect($breakdown->remainingDays)->toBe(10)
        ->and($breakdown->totalDays)->toBe(30)
        ->and($breakdown->creditMinor)->toBe(1033)
        ->and($breakdown->totalDueMinor)->toBe(14900 - 1033)
        ->and($breakdown->currency)->toBe('USD');
});

it('credits nearly the full amount on the first day of the period', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));

    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 14900,
        currentPeriodAmountPaidMinor: 4900,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    expect($breakdown->remainingDays)->toBe(30)
        ->and($breakdown->totalDays)->toBe(30)
        ->and($breakdown->creditMinor)->toBe(4900)
        ->and($breakdown->totalDueMinor)->toBe(10000);
});

it('gives zero credit on the last day when remaining days are zero', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'));

    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 14900,
        currentPeriodAmountPaidMinor: 4900,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    expect($breakdown->remainingDays)->toBe(0)
        ->and($breakdown->creditMinor)->toBe(0)
        ->and($breakdown->totalDueMinor)->toBe(14900);
});

it('gives zero credit when the period has already ended', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-05 12:00:00', 'UTC'));

    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 14900,
        currentPeriodAmountPaidMinor: 4900,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    expect($breakdown->remainingDays)->toBe(0)
        ->and($breakdown->creditMinor)->toBe(0)
        ->and($breakdown->totalDueMinor)->toBe(14900);
});

it('handles yearly to higher yearly upgrades', function (): void {
    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 399000,
        currentPeriodAmountPaidMinor: 149000,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    expect($breakdown->totalDays)->toBe(365)
        ->and($breakdown->remainingDays)->toBe(41)
        ->and($breakdown->creditMinor)->toBe((int) round(149000 * 41 / 365))
        ->and($breakdown->totalDueMinor)->toBe(399000 - $breakdown->creditMinor);
});

it('handles monthly to yearly upgrades', function (): void {
    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 149000,
        currentPeriodAmountPaidMinor: 14900,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    expect($breakdown->newPriceMinor)->toBe(149000)
        ->and($breakdown->creditMinor)->toBe((int) round(14900 * 10 / 30))
        ->and($breakdown->totalDueMinor)->toBe(149000 - $breakdown->creditMinor);
});

it('caps credit at the new plan price', function (): void {
    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 1000,
        currentPeriodAmountPaidMinor: 4900,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    expect($breakdown->creditMinor)->toBe(1000)
        ->and($breakdown->totalDueMinor)->toBe(0);
});

it('returns a zero total when credit equals the new price', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));

    $breakdown = app(ProrationCalculator::class)->forUpgrade(
        newPriceMinor: 4900,
        currentPeriodAmountPaidMinor: 4900,
        currentPeriodStartsAt: CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'),
        currentPeriodEndsAt: CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'),
        currency: 'USD',
    );

    expect($breakdown->totalDueMinor)->toBe(0)
        ->and($breakdown->creditMinor)->toBe(4900);
});

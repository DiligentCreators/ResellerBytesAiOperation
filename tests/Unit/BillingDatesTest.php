<?php

use App\Models\Tenant;
use App\Support\BillingDates;
use Carbon\CarbonImmutable;

it('formats dates in UTC when the tenant has no timezone', function (): void {
    $tenant = new Tenant(['timezone' => null]);
    $at = CarbonImmutable::parse('2027-11-21T15:30:00Z');

    expect(BillingDates::humanDate($at, $tenant))->toBe('Nov 21, 2027')
        ->and(BillingDates::timezone($tenant))->toBe('UTC')
        ->and(BillingDates::humanDate($at, null))->toBe('Nov 21, 2027');
});

it('formats dates in the workspace timezone when set', function (): void {
    $tenant = new Tenant(['timezone' => 'America/New_York']);
    // 2027-11-22 02:00 UTC is still Nov 21 in America/New_York.
    $at = CarbonImmutable::parse('2027-11-22T02:00:00Z');

    expect(BillingDates::humanDate($at, $tenant))->toBe('Nov 21, 2027')
        ->and(BillingDates::timezone($tenant))->toBe('America/New_York');
});

it('returns an empty string for null or empty values', function (): void {
    expect(BillingDates::humanDate(null))->toBe('')
        ->and(BillingDates::humanDate(''))->toBe('');
});

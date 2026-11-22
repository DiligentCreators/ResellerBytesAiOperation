<?php

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Postgres');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Assign runtime random prices (tier-ordered) and activate seeded catalog plans
 * so checkout flows can purchase them. Never hardcodes commercial amounts.
 */
function priceSeededCatalog(): void
{
    $base = fake()->numberBetween(1000, 5000);

    $tiers = [
        'starter' => [$base, $base * 10],
        'growth' => [$base * 3, $base * 30],
        'business' => [$base * 8, $base * 80],
    ];

    foreach ($tiers as $code => [$monthly, $yearly]) {
        Plan::query()->where('code', $code)->update([
            'price_monthly_minor' => $monthly,
            'price_yearly_minor' => $yearly,
            'is_active' => true,
            'prices_confirmed_at' => now(),
        ]);
    }
}

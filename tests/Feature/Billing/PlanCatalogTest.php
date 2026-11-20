<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;

it('seeds the public plan catalog with placeholder tiers', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->count())->toBe(4)
        ->and(Plan::query()->where('code', 'starter')->value('is_public'))->toBeTrue()
        ->and(Plan::query()->where('code', 'enterprise')->value('is_contact_sales'))->toBeTrue()
        ->and(Plan::query()->where('code', 'growth')->value('entitlements'))->toHaveKey('limits');
});

it('exposes only public active plans on the pricing page', function () {
    $this->seed(PlanSeeder::class);
    Plan::query()->where('code', 'business')->update(['is_public' => false]);

    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/pricing')
            ->has('plans', 3)
            ->where('pricesArePlaceholders', true));
});

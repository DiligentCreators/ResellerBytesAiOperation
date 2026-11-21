<?php

use Database\Seeders\PlanSeeder;

it('renders the public pricing page from the database catalog', function () {
    $this->seed(PlanSeeder::class);
    priceSeededCatalog();

    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/pricing')
            ->has('faq')
            ->has('plans', 4));
});

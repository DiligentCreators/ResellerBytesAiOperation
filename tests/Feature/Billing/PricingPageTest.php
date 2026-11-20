<?php

use Database\Seeders\PlanSeeder;

it('renders the public pricing page', function () {
    $this->seed(PlanSeeder::class);

    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/pricing')
            ->has('faq')
            ->has('plans'));
});

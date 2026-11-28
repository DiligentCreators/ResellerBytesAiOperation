<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the product gtm page with objections and packaging', function () {
    $this->get('/product')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('marketing/product')
            ->has('objections', 3)
            ->has('packaging')
            ->where('marketplace.positioning', fn ($value) => str_contains((string) $value, 'Ops intelligence'))
            ->missing('marketplace.listing_checklist')
            ->where('packaging', fn ($packaging) => collect($packaging)->pluck('name')->intersect([
                'Starter',
                'Growth',
                'Business',
                'Enterprise',
            ])->isNotEmpty() || collect($packaging)->isNotEmpty()));
});

it('renders sanitized case studies', function () {
    $this->get('/case-studies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('marketing/case-studies')
            ->has('studies', 3)
            ->where('studies.0.slug', 'cron-stall-multi-brand'));
});

it('includes whmcs buyer objections on pricing faq', function () {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('marketing/pricing')
            ->has('faq')
            ->where('faq', fn ($faq) => collect($faq)->contains(
                fn ($item) => str_contains((string) data_get($item, 'question'), 'AI break')
            )));
});

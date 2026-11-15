<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create([
        'email_verified_at' => now(),
    ]));
});

it('renders the operations dashboard with its initial metrics', function () {
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('stats.active_integrations')
            ->has('stats.total_runs')
            ->has('stats.open_findings')
            ->has('latestRuns')
            ->where('addon.version', '1.0.0'));
});

it('renders the WHMCS addon setup page with download capability props', function () {
    $this->get('/whmcs-addon/setup')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whmcs-addon/setup')
            ->where('addonVersion', '1.0.0')
            ->has('downloadAvailable')
            ->has('aiConfigured'));
});

it('renders the authenticated integrations, diagnostic runs, and findings pages', function () {
    $this->get('/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('integrations/index')->has('integrations.data'));

    $this->get('/diagnostic-runs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('diagnostic-runs/index')->has('runs.data'));

    $this->get('/findings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('findings/index')->has('findings.data'));
});

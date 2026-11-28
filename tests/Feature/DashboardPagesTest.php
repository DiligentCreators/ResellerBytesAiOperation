<?php

use App\Models\User;
use App\Support\WhmcsAddonVersion;
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
            ->where('addon.version', WhmcsAddonVersion::packaged()));
});

it('renders the WHMCS addon setup page with download capability props', function () {
    $this->get('/whmcs-addon/setup')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whmcs-addon/setup')
            ->where('addonVersion', WhmcsAddonVersion::packaged())
            ->has('downloadAvailable')
            ->has('aiConfigured'));
});

it('renders the setup page addonVersion from the packaged source when config is drifted', function () {
    config(['services.whmcs_addon.version' => '1.1.0']);

    $this->get('/whmcs-addon/setup')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whmcs-addon/setup')
            ->where('addonVersion', '1.2.4'));
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
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/index')
            ->has('findings.data')
            ->has('health.last_scan_clean')
            ->has('filters.status'));
});

it('exposes last scan clean health on the dashboard', function () {
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('health.last_scan_clean')
            ->has('health.sparkline')
            ->has('needsYouNow')
            ->has('timeSaved')
            ->has('installations'));
});

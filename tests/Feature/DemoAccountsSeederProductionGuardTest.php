<?php

use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to seed demo accounts when APP_ENV is production', function () {
    $this->seed(PlanSeeder::class);

    $previous = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        // Call run() directly — artisan db:seed prompts for confirmation in production.
        (new DemoAccountsSeeder)->run();
    } finally {
        app()->detectEnvironment(fn (): string => $previous);
    }

    expect(User::query()->where('email', 'admin@resellerbytes.test')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'customer@demo-tenant.test')->exists())->toBeFalse();
});

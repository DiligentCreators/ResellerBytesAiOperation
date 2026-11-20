<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
        ]);

        // DemoAccountsSeeder self-guards in production; skip the call entirely there too.
        if (! app()->environment('production')) {
            $this->call([
                DemoAccountsSeeder::class,
            ]);
        }
    }
}

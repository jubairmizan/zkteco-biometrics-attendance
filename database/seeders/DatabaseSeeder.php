<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only creates the dashboard login, so `php artisan db:seed` is safe on a live server.
     * Demo data (sample users and a week of fake punches) is separate, for local use only:
     *   php artisan db:seed --class=DemoDataSeeder
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);
    }
}

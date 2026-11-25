<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sample users (password "password") and a week of fake punches, for trying the dashboard locally.
 * Refuses to run in production, where those accounts would be a way in.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoDataSeeder creates logins with the password "password" and fake punches; it does not run in production.');
        }

        $this->call([
            UserSeeder::class,
            AttendanceLogSeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the dashboard login from ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD (config/auth.php seed_admin).
 *
 * - Safe to run again: if the email already exists, that account is left exactly as it is
 *   (its password is never reset by a re-run).
 * - No ADMIN_PASSWORD: a random 16-character password is generated and printed once.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('auth.seed_admin');
        $name = $config['name'] ?: 'Admin';
        $email = $config['email'];
        $password = $config['password'];

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Login {$email} already exists; left unchanged.");

            return;
        }

        $generated = $password === null || $password === '';
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Cannot seed the admin login: ' . implode(' ', $validator->errors()->all()) . ' Check ADMIN_EMAIL / ADMIN_PASSWORD in .env.');
        }

        // The "hashed" cast on User hashes the password
        $user = User::create(compact('name', 'email', 'password'));
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->command?->info("Created dashboard login {$email}.");

        if ($generated) {
            $this->command?->warn("Password: {$password}");
            $this->command?->warn('This is shown only once. Save it now, or set ADMIN_PASSWORD in .env before seeding.');
        }
    }
}

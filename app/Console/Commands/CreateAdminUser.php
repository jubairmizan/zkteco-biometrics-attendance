<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'user:create-admin
                            {--name= : Display name}
                            {--email= : Login email}
                            {--password= : Password (asked for if left out, so it stays out of shell history)}';

    protected $description = 'Create a user who can log in to the attendance dashboard';

    public function handle(): int
    {
        $data = [
            'name'     => $this->option('name') ?: $this->ask('Name', 'Admin'),
            'email'    => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->option('password') ?: $this->secret('Password (at least 8 characters)'),
        ];

        $validator = Validator::make($data, [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // The "hashed" cast on User hashes the password
        $user = User::create($data);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Created {$user->email}. Log in at " . route('login'));

        return self::SUCCESS;
    }
}

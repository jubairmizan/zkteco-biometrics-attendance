<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_seed_creates_the_login_from_env_and_it_can_log_in(): void
    {
        config(['auth.seed_admin' => ['name' => 'Office Admin', 'email' => 'boss@example.com', 'password' => 'very-secret-1']]);

        $this->artisan('db:seed')->expectsOutputToContain('Created dashboard login boss@example.com')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(0, AttendanceLog::count()); // no demo data on a plain db:seed
        $this->assertNotNull(User::first()->email_verified_at);

        $this->post('/login', ['email' => 'boss@example.com', 'password' => 'very-secret-1'])->assertRedirect(route('attendance.index'));
        $this->assertAuthenticated();
    }

    public function test_without_a_password_a_random_one_is_generated_and_shown(): void
    {
        config(['auth.seed_admin' => ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => null]]);

        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
            ->expectsOutputToContain('Password: ')
            ->expectsOutputToContain('shown only once')
            ->assertSuccessful();

        $this->assertFalse(Hash::check('password', User::first()->password));
    }

    public function test_running_again_leaves_the_existing_login_alone(): void
    {
        config(['auth.seed_admin' => ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'first-password']]);
        $this->seed(AdminUserSeeder::class);

        config(['auth.seed_admin' => ['name' => 'Renamed', 'email' => 'admin@example.com', 'password' => 'second-password']]);
        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])->expectsOutputToContain('already exists')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame('Admin', User::first()->name);
        $this->assertTrue(Hash::check('first-password', User::first()->password));
    }

    public function test_short_password_or_bad_email_is_refused(): void
    {
        config(['auth.seed_admin' => ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'short']]);
        $this->expectException(RuntimeException::class);
        $this->seed(AdminUserSeeder::class);
    }

    public function test_bad_email_is_refused(): void
    {
        config(['auth.seed_admin' => ['name' => 'Admin', 'email' => 'not-an-email', 'password' => 'long-enough-pw']]);
        $this->expectException(RuntimeException::class);
        $this->seed(AdminUserSeeder::class);
    }

    public function test_demo_seeders_refuse_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        foreach ([DemoDataSeeder::class, UserSeeder::class] as $seeder) {
            try {
                // Called directly: `db:seed` in production first asks "Are you sure?" (or needs --force)
                $this->app->make($seeder)->run();
                $this->fail("{$seeder} ran in production");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('production', $e->getMessage());
            }
        }

        $this->assertSame(0, User::count());
    }

    public function test_demo_data_seeder_works_locally(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(6, User::count());
        $this->assertGreaterThan(0, AttendanceLog::count());
    }
}

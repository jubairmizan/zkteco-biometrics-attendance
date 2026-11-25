<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AttendanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Admin']);
    }

    private function punch(array $attributes = []): AttendanceLog
    {
        return AttendanceLog::create($attributes + [
            'device_user_id'    => '1001',
            'device_ip'         => '192.168.200.50',
            'device_sn'         => 'ABC123',
            'punch_time'        => now(),
            'punch_type'        => 'check_in',
            'verification_type' => 'face',
        ]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/attendance', '/attendance/logs', '/attendance/latest', '/attendance/export'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->getJson('/attendance/latest')->assertUnauthorized();
    }

    public function test_old_pull_mode_routes_are_gone(): void
    {
        $this->actingAs($this->admin);

        $this->delete('/attendance/clear-device-logs')->assertNotFound();
        $this->post('/attendance/sync-device-users')->assertNotFound();
        $this->get('/attendance/test-connection')->assertNotFound();
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'correct-horse']);

        $this->post('/login', ['email' => 'boss@example.com', 'password' => 'wrong-pass'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'boss@example.com', 'password' => 'correct-horse'])
            ->assertRedirect(route('attendance.index'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'boss@example.com']);

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => 'boss@example.com', 'password' => 'wrong-pass']);
        }

        $this->post('/login', ['email' => 'boss@example.com', 'password' => 'wrong-pass'])->assertStatus(429);
    }

    public function test_dashboard_lists_punches_newest_first_with_names(): void
    {
        $rahim = User::factory()->create(['name' => 'Rahim Uddin', 'device_user_id' => '1001']);
        $this->punch(['user_id' => $rahim->id, 'punch_time' => now()->subHour()]);
        $this->punch(['device_user_id' => '2002', 'punch_time' => now(), 'punch_type' => 'overtime_out']);
        Device::touchFromRequest('ABC123', '192.168.200.50');

        $this->actingAs($this->admin)
            ->get('/attendance')
            ->assertOk()
            ->assertSeeInOrder(['2002', 'OT-Out', 'Rahim Uddin', '1001', 'Check-In'])
            ->assertSee('No name yet')
            ->assertSee('ABC123')
            ->assertSee('192.168.200.50');
    }

    public function test_dashboard_shows_empty_state_and_setup_hint(): void
    {
        $this->actingAs($this->admin)
            ->get('/attendance')
            ->assertOk()
            ->assertSee('No device has connected yet')
            ->assertSee('No punches yet');
    }

    public function test_filters_narrow_the_list(): void
    {
        $rahim = User::factory()->create(['name' => 'Rahim Uddin', 'device_user_id' => '1001']);
        $this->punch(['user_id' => $rahim->id, 'punch_time' => '2026-11-20 09:00:00']);
        $this->punch(['device_user_id' => '2002', 'punch_time' => '2026-11-24 09:00:00', 'punch_type' => 'check_out']);
        $this->punch(['device_user_id' => '3003', 'punch_time' => '2026-11-24 18:00:00', 'device_sn' => 'OTHER']);

        $this->actingAs($this->admin);

        $this->get('/attendance?search=rahim')->assertSee('Rahim Uddin')->assertDontSee('2002')->assertDontSee('3003');
        $this->get('/attendance?search=2002')->assertSee('2002')->assertDontSee('Rahim Uddin');
        $this->get('/attendance?start_date=2026-11-24&end_date=2026-11-24')
            ->assertSee('2002')->assertSee('3003')->assertDontSee('Rahim Uddin'); // whole end day included
        $this->get('/attendance?punch_type=check_out')->assertSee('2002')->assertDontSee('3003');
        $this->get('/attendance?device_sn=OTHER')->assertSee('3003')->assertDontSee('2002');
    }

    public function test_invalid_filters_are_rejected_not_crashing(): void
    {
        $this->actingAs($this->admin)
            ->get('/attendance?start_date=not-a-date&punch_type=hacked')
            ->assertSessionHasErrors(['start_date', 'punch_type']);
    }

    public function test_pagination_keeps_filters(): void
    {
        foreach (range(1, 30) as $i) {
            $this->punch(['device_user_id' => (string) $i, 'punch_time' => now()->subMinutes($i)]);
        }

        $this->actingAs($this->admin)
            ->get('/attendance?punch_type=check_in')
            ->assertOk()
            ->assertSee('punch_type=check_in&amp;page=2', false);
    }

    public function test_latest_endpoint_reports_newest_id_and_device_status(): void
    {
        $log = $this->punch();
        Device::touchFromRequest('ABC123', '192.168.200.50');

        $this->actingAs($this->admin)
            ->getJson('/attendance/latest')
            ->assertOk()
            ->assertJson([
                'latest_id' => $log->id,
                'today'     => 1,
                'devices'   => [['serial_number' => 'ABC123', 'online' => true]],
            ]);
    }

    public function test_json_logs_endpoint_filters_and_caps_page_size(): void
    {
        $this->punch(['punch_type' => 'check_out']);
        $this->punch(['punch_type' => 'check_in', 'device_user_id' => '9']);

        $this->actingAs($this->admin)
            ->getJson('/attendance/logs?punch_type=check_out&per_page=100000')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 200);
    }

    public function test_export_uses_filters_and_neutralises_formulas(): void
    {
        $this->punch(['device_user_id' => '=HYPERLINK("http://evil")', 'punch_type' => 'check_out']);
        $this->punch(['device_user_id' => '5555', 'punch_type' => 'check_in']);

        $response = $this->actingAs($this->admin)->get('/attendance/export?punch_type=check_out');

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('5555', $csv);
    }

    public function test_user_summary_includes_whole_end_day(): void
    {
        $rahim = User::factory()->create(['device_user_id' => '1001']);
        $this->punch(['user_id' => $rahim->id, 'punch_time' => '2026-11-24 09:00:00']);
        $this->punch(['user_id' => $rahim->id, 'punch_time' => '2026-11-24 18:00:00', 'punch_type' => 'check_out']);

        $this->actingAs($this->admin)
            ->getJson("/attendance/user/{$rahim->id}/summary?start_date=2026-11-24&end_date=2026-11-24")
            ->assertOk()
            ->assertJsonPath('total_days', 1)
            ->assertJsonPath('total_check_ins', 1)
            ->assertJsonPath('total_check_outs', 1)
            ->assertJsonMissingPath('user.password');
    }

    public function test_mark_processed_validates_and_counts_updated_rows(): void
    {
        $log = $this->punch();

        $this->actingAs($this->admin)->postJson('/attendance/mark-processed', [])->assertUnprocessable();
        $this->actingAs($this->admin)
            ->postJson('/attendance/mark-processed', ['log_ids' => [$log->id, 99999]])
            ->assertOk()
            ->assertJsonPath('processed_count', 1);

        $this->assertTrue($log->fresh()->is_processed);
    }

    public function test_create_admin_command(): void
    {
        $exit = Artisan::call('user:create-admin', [
            '--name' => 'Owner', '--email' => 'owner@example.com', '--password' => 'long-enough-pw',
        ]);

        $this->assertSame(0, $exit);
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'long-enough-pw']);
        $this->assertAuthenticated();

        $this->assertSame(1, Artisan::call('user:create-admin', [
            '--name' => 'Dup', '--email' => 'owner@example.com', '--password' => 'long-enough-pw',
        ]));
        $this->assertSame(1, Artisan::call('user:create-admin', [
            '--name' => 'Short', '--email' => 'short@example.com', '--password' => 'short',
        ]));
    }
}

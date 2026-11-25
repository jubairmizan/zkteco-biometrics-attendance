<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\PunchClassifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Tests\TestCase;

class AttendanceTimeRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['zkteco.time_rules' => [
            'enabled' => true, 'late_after' => '09:30', 'check_in_until' => '12:00', 'check_out_from' => '18:00',
        ]]);
        config(['error-notifier.drivers.telegram.bot_token' => null]);
        Http::fake();
        ErrorNotifier::spy();
    }

    private function classify(string $time, int $status = 0): array
    {
        return (new PunchClassifier())->classify(Carbon::parse("2026-11-25 {$time}"), $status);
    }

    private function push(string $body)
    {
        return $this->call('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    /**
     * @dataProvider timeCases
     */
    public function test_time_of_day_decides_type_and_flag(string $time, int $status, string $type, ?string $flag): void
    {
        $this->assertSame(['punch_type' => $type, 'attendance_status' => $flag], $this->classify($time, $status));
    }

    public static function timeCases(): array
    {
        return [
            'early morning'                       => ['07:45:00', 0, 'check_in', null],
            'on time, boundary'                   => ['09:30:00', 0, 'check_in', null],
            'one second late'                     => ['09:30:01', 0, 'check_in', 'late'],
            'noon is still check-in'              => ['12:00:00', 0, 'check_in', 'late'],
            'device said out in the morning'      => ['08:00:00', 1, 'check_in', null],
            'just after noon'                     => ['12:00:01', 0, 'check_out', 'early_leave'],
            'afternoon'                           => ['15:00:00', 0, 'check_out', 'early_leave'],
            '6 pm sharp is on time'               => ['18:00:00', 0, 'check_out', null],
            'evening'                             => ['19:10:00', 1, 'check_out', null],
            'just before midnight'                => ['23:59:59', 0, 'check_out', null],
            'break key kept'                      => ['13:00:00', 2, 'break_out', null],
            'break-in key kept in the morning'    => ['10:00:00', 3, 'break_in', null],
            'overtime key kept'                   => ['20:00:00', 4, 'overtime_in', null],
        ];
    }

    public function test_rules_can_be_turned_off(): void
    {
        config(['zkteco.time_rules.enabled' => false]);

        $this->assertSame(['punch_type' => 'check_in', 'attendance_status' => null], $this->classify('19:00:00', 0));
        $this->assertSame(['punch_type' => 'check_out', 'attendance_status' => null], $this->classify('08:00:00', 1));
    }

    public function test_times_are_configurable_and_validated(): void
    {
        config(['zkteco.time_rules.late_after' => '9:00', 'zkteco.time_rules.check_out_from' => '17:00']);
        $this->assertSame('late', $this->classify('09:15:00')['attendance_status']);
        $this->assertNull($this->classify('17:05:00')['attendance_status']);

        config(['zkteco.time_rules.check_in_until' => 'noon']);
        $this->expectException(InvalidArgumentException::class);
        $this->classify('10:00:00');
    }

    public function test_pushed_punches_are_classified_and_keep_the_device_status(): void
    {
        // A face device sends status 0 for everything
        $this->push("1001\t2026-11-25 09:10:00\t0\t15\n1002\t2026-11-25 10:05:00\t0\t15\n1001\t2026-11-25 16:00:00\t0\t15\n1002\t2026-11-25 18:45:00\t0\t15")
            ->assertSeeText('OK: 4');

        $rows = AttendanceLog::orderBy('punch_time')->get(['device_user_id', 'punch_type', 'attendance_status', 'device_status'])->toArray();
        $this->assertSame([
            ['device_user_id' => '1001', 'punch_type' => 'check_in',  'attendance_status' => null,          'device_status' => 0],
            ['device_user_id' => '1002', 'punch_type' => 'check_in',  'attendance_status' => 'late',        'device_status' => 0],
            ['device_user_id' => '1001', 'punch_type' => 'check_out', 'attendance_status' => 'early_leave', 'device_status' => 0],
            ['device_user_id' => '1002', 'punch_type' => 'check_out', 'attendance_status' => null,          'device_status' => 0],
        ], $rows);
    }

    public function test_telegram_shows_the_flag(): void
    {
        config(['error-notifier.drivers.telegram.bot_token' => 't', 'error-notifier.drivers.telegram.chat_id' => '1']);

        $this->push("1001\t2026-11-25 10:05:00\t0\t15\n1001\t2026-11-25 15:00:00\t0\t15");

        Http::assertSent(fn ($r) => str_contains($r['text'], '10:05:00 | Check-In (Late) | Face')
            && str_contains($r['text'], '15:00:00 | Check-Out (Early leave) | Face'));
    }

    public function test_dashboard_shows_badges_late_count_and_filters_by_status(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 20:00:00'));
        $this->push("4101\t2026-11-25 10:05:00\t0\t15\n4202\t2026-11-25 09:00:00\t0\t15\n4303\t2026-11-25 15:00:00\t0\t15");

        $this->actingAs(User::factory()->create());

        $this->get('/attendance')->assertOk()
            ->assertSee('Late today')
            ->assertSee('Early leave')
            ->assertSee('>1</p>', false); // 1 person late today

        $this->get('/attendance?attendance_status=late')->assertSee('4101')->assertDontSee('4202')->assertDontSee('4303');
        $this->get('/attendance?attendance_status=early_leave')->assertSee('4303')->assertDontSee('4101');
        $this->get('/attendance?attendance_status=bogus')->assertSessionHasErrors('attendance_status');

        $csv = $this->get('/attendance/export?attendance_status=late')->streamedContent();
        $this->assertStringContainsString('Status', $csv);
        $this->assertStringContainsString('Late', $csv);
        $this->assertStringNotContainsString('4202', $csv);
    }

    public function test_employee_month_counts_late_and_early_days(): void
    {
        $this->push(implode("\n", [
            "1001\t2026-11-23 09:00:00\t0\t15", "1001\t2026-11-23 18:30:00\t0\t15", // on time both ways
            "1001\t2026-11-24 09:45:00\t0\t15", "1001\t2026-11-24 18:05:00\t0\t15", // late in
            "1001\t2026-11-25 09:10:00\t0\t15", "1001\t2026-11-25 13:00:00\t0\t15", "1001\t2026-11-25 16:30:00\t0\t15", // early out
        ]));
        $employee = Employee::where('pin', '1001')->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get("/employees/{$employee->id}?month=2026-11")
            ->assertOk()
            ->assertSee('1</div><div class="text-xs text-gray-500">late (after 09:30)', false)
            ->assertSee('1</div><div class="text-xs text-gray-500">left before 18:00', false)
            ->assertSeeInOrder(['Wed, Nov 25', '16:30', 'Early', 'Tue, Nov 24', '09:45', 'Late']);
    }

    public function test_reclassify_command_updates_old_rows(): void
    {
        // Stored before the rules existed: everything check_in, no device_status
        foreach (['2026-11-20 08:55:00', '2026-11-20 10:00:00', '2026-11-20 17:00:00', '2026-11-20 19:00:00'] as $i => $time) {
            AttendanceLog::create(['device_user_id' => (string) $i, 'device_ip' => 'x', 'punch_time' => $time, 'punch_type' => 'check_in']);
        }
        AttendanceLog::create(['device_user_id' => '9', 'device_ip' => 'x', 'punch_time' => '2026-11-20 13:00:00', 'punch_type' => 'break_out']);

        $this->artisan('attendance:reclassify --dry-run')->expectsOutputToContain('Would update 5 of 5')->assertSuccessful();
        $this->assertSame(4, AttendanceLog::where('punch_type', 'check_in')->count());

        $this->artisan('attendance:reclassify')->expectsOutputToContain('Updated 5 of 5')->assertSuccessful();

        $this->assertSame(
            [['check_in', null], ['check_in', 'late'], ['break_out', null], ['check_out', 'early_leave'], ['check_out', null]],
            AttendanceLog::orderBy('punch_time')->get()->map(fn ($l) => [$l->punch_type, $l->attendance_status])->all()
        );

        // Nothing left to change
        $this->artisan('attendance:reclassify')->expectsOutputToContain('Updated 0 of 5')->assertSuccessful();
        $this->artisan('attendance:reclassify --from=bad')->assertFailed();
    }
}

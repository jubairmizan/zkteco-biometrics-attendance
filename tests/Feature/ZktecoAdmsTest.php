<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Tests\TestCase;

class ZktecoAdmsTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake Telegram API does; a test can replace it (a second Http::fake() would not override). */
    private \Closure $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'error-notifier.drivers.telegram.bot_token' => 'test-token',
            'error-notifier.drivers.telegram.chat_id'   => '-100123',
        ]);

        $this->telegram = fn () => Http::response(['ok' => true]);
        Http::fake(fn ($request) => ($this->telegram)($request));
        ErrorNotifier::spy();
    }

    private function postAttlog(string $body, string $query = 'SN=ABC123&table=ATTLOG')
    {
        return $this->call('POST', "/iclock/cdata?{$query}", [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_routes_are_reachable_without_api_prefix(): void
    {
        $this->get('/iclock/cdata?SN=ABC123&options=all')->assertOk();
        // A new device's first poll carries the "send your user list" command (see EmployeeDetailsTest)
        $this->get('/iclock/getrequest?SN=ABC123')->assertOk()->assertSeeText('DATA QUERY USERINFO');
        $this->get('/iclock/getrequest?SN=ABC123')->assertOk()->assertSeeText('OK');
        $this->post('/iclock/devicecmd?SN=ABC123')->assertOk()->assertSeeText('OK');
        $this->get('/api/iclock/cdata?SN=ABC123')->assertNotFound();
    }

    public function test_handshake_returns_device_options_and_registers_device(): void
    {
        $response = $this->get('/iclock/cdata?SN=ABC123&options=all&pushver=2.4.1');

        $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('GET OPTION FROM: ABC123', $response->getContent());
        $this->assertStringContainsString('ATTLOGStamp=None', $response->getContent());
        $this->assertStringContainsString('Realtime=1', $response->getContent());
        Http::assertNothingSent();

        $device = Device::where('serial_number', 'ABC123')->firstOrFail();
        $this->assertSame('2.4.1', $device->push_version);
        $this->assertSame('127.0.0.1', $device->ip_address);
        $this->assertTrue($device->isOnline());
    }

    public function test_polling_updates_last_seen(): void
    {
        $this->travelTo(now()->subHour());
        $this->get('/iclock/cdata?SN=ABC123&options=all');
        $this->travelBack();

        $this->assertFalse(Device::first()->isOnline());

        $this->get('/iclock/getrequest?SN=ABC123');

        $this->assertTrue(Device::first()->isOnline());
        $this->assertSame(1, Device::count());
    }

    public function test_punches_are_stored_logged_and_sent_as_one_telegram_message(): void
    {
        Log::spy();

        $this->postAttlog("1001\t2026-11-25 09:00:00\t0\t15\t0\r\n1002\t2026-11-25 18:01:00\t1\t1\t0\r\n")
            ->assertOk()
            ->assertSeeText('OK: 2');

        $this->assertDatabaseCount('attendance_logs', 2);
        $this->assertDatabaseHas('attendance_logs', [
            'device_user_id'    => '1001',
            'device_sn'         => 'ABC123',
            'device_ip'         => '127.0.0.1',
            'punch_time'        => '2026-11-25 09:00:00',
            'punch_type'        => 'check_in',
            'verification_type' => 'face',
            'work_code'         => 0,
            'is_processed'      => false,
        ]);
        $this->assertDatabaseHas('attendance_logs', ['device_user_id' => '1002', 'punch_type' => 'check_out', 'verification_type' => 'fingerprint']);
        $this->assertNotNull(Device::first()->last_punch_at);

        Log::shouldHaveReceived('info')->with('ZKTeco Punch', \Mockery::on(fn ($c) => $c['pin'] === '1001' && $c['sn'] === 'ABC123'));

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) {
            return str_contains($r->url(), '/bottest-token/sendMessage')
                && $r['chat_id'] === '-100123'
                && $r['text'] === "ZKTeco Punch (device ABC123)\n"
                    . "• PIN 1001 | 2026-11-25 09:00:00 | Check-In | Face\n"
                    . "• PIN 1002 | 2026-11-25 18:01:00 | Check-Out | Fingerprint";
        });
        ErrorNotifier::shouldNotHaveReceived('report');
    }

    public function test_punch_is_linked_to_the_user_with_that_pin(): void
    {
        $user = User::factory()->create(['name' => 'Rahim', 'device_user_id' => '1001']);

        $this->postAttlog("1001\t2026-11-25 09:00:00\t0\t15")->assertSeeText('OK: 1');

        $this->assertSame($user->id, AttendanceLog::first()->user_id);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], '• Rahim (PIN 1001) | 2026-11-25 09:00:00'));
    }

    public function test_resent_punches_are_not_stored_or_announced_twice(): void
    {
        $body = "1001\t2026-11-25 09:00:00\t0\t15\n1002\t2026-11-25 09:05:00\t0\t15";

        $this->postAttlog($body)->assertSeeText('OK: 2');
        $this->postAttlog($body . "\n1003\t2026-11-25 09:10:00\t0\t15")->assertSeeText('OK: 3');

        $this->assertDatabaseCount('attendance_logs', 3);
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'PIN 1003') && ! str_contains($r['text'], 'PIN 1001'));
    }

    public function test_same_pin_and_time_from_two_devices_are_both_kept(): void
    {
        $this->postAttlog("1001\t2026-11-25 09:00:00", 'SN=DEV-A&table=ATTLOG');
        $this->postAttlog("1001\t2026-11-25 09:00:00", 'SN=DEV-B&table=ATTLOG');

        $this->assertDatabaseCount('attendance_logs', 2);
    }

    public function test_all_status_codes_are_stored_including_overtime(): void
    {
        $body = implode("\n", array_map(fn ($s) => "7\t2026-11-25 12:0{$s}:00\t{$s}\t1", range(0, 5)));

        $this->postAttlog($body)->assertSeeText('OK: 6');

        $this->assertSame(
            ['check_in', 'check_out', 'break_out', 'break_in', 'overtime_in', 'overtime_out'],
            AttendanceLog::orderBy('punch_time')->pluck('punch_type')->all()
        );
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'Break-Out') && str_contains($r['text'], 'OT-Out'));
    }

    public function test_unknown_verify_mode_is_kept_as_a_code(): void
    {
        $this->postAttlog("7\t2026-11-25 12:00:00\t0\t0")->assertSeeText('OK: 1');

        $this->assertSame('mode_0', AttendanceLog::first()->verification_type);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'Check-In | Mode 0'));
    }

    public function test_pin_zero_is_not_treated_as_malformed(): void
    {
        $this->postAttlog("0\t2026-11-25 09:00:00\t0\t1\t0")->assertSeeText('OK: 1');

        $this->assertDatabaseHas('attendance_logs', ['device_user_id' => '0']);
        ErrorNotifier::shouldNotHaveReceived('report');
    }

    public function test_missing_optional_columns_use_defaults(): void
    {
        $this->postAttlog("1001\t2026-11-25 09:00:00")->assertSeeText('OK: 1');

        $this->assertDatabaseHas('attendance_logs', ['punch_type' => 'check_in', 'verification_type' => 'face', 'work_code' => null]);
    }

    public function test_malformed_lines_are_reported_and_rest_of_batch_is_kept(): void
    {
        $this->postAttlog("garbage\n1001\tnot-a-date\t0\n1002\t2026-13-45 09:00:00\t0\n1003\t2026-11-25 09:00:00\t0\t15")
            ->assertOk()
            ->assertSeeText('OK: 1');

        $this->assertDatabaseCount('attendance_logs', 1);
        ErrorNotifier::shouldHaveReceived('report')->times(3)
            ->with(\Mockery::on(fn ($e) => $e instanceof \UnexpectedValueException));
    }

    public function test_empty_body_stores_nothing(): void
    {
        $this->postAttlog('')->assertOk()->assertSeeText('OK: 0');

        $this->assertDatabaseCount('attendance_logs', 0);
        Http::assertNothingSent();
    }

    public function test_other_tables_are_acknowledged_and_ignored(): void
    {
        $this->postAttlog("OPLOG 4\t0\t2026-11-25 09:00:00\t0\t0\t0\t0", 'SN=ABC123&table=OPERLOG')
            ->assertOk()->assertSeeText('OK');

        $this->assertDatabaseCount('attendance_logs', 0);
        Http::assertNothingSent();
    }

    public function test_missing_or_array_serial_number_does_not_crash(): void
    {
        $this->postAttlog("1001\t2026-11-25 09:00:00", 'table=ATTLOG')->assertOk();
        $this->postAttlog("1002\t2026-11-25 09:00:00", 'SN[]=x&table=ATTLOG')->assertOk();

        $this->assertSame(2, AttendanceLog::where('device_sn', 'unknown')->count());
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r['text'], 'ZKTeco Punch (device unknown)'));
    }

    public function test_database_failure_returns_error_so_the_device_retries(): void
    {
        $this->get('/iclock/cdata?SN=ABC123&options=all'); // device row exists
        DB::statement('DROP TABLE attendance_logs');

        $this->postAttlog("1001\t2026-11-25 09:00:00")
            ->assertStatus(500)
            ->assertSeeText('ERROR');

        ErrorNotifier::shouldHaveReceived('report');
        Http::assertNothingSent();
    }

    public function test_telegram_failure_still_returns_ok_and_does_not_log_token(): void
    {
        $this->telegram = fn () => Http::response(['ok' => false], 500);
        Log::spy();

        $this->postAttlog("1001\t2026-11-25 09:00:00")->assertOk()->assertSeeText('OK: 1');

        $this->assertDatabaseCount('attendance_logs', 1);
        Log::shouldHaveReceived('warning')->with('ZKTeco punch Telegram message failed', \Mockery::on(
            fn ($c) => ! str_contains(json_encode($c), 'test-token') && $c['status'] === 500
        ));
    }

    public function test_telegram_connection_error_still_returns_ok(): void
    {
        $this->telegram = fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error for https://api.telegram.org/bottest-token/sendMessage');
        Log::spy();

        $this->postAttlog("1001\t2026-11-25 09:00:00")->assertOk()->assertSeeText('OK: 1');

        Log::shouldHaveReceived('warning')->with('ZKTeco punch Telegram message failed', \Mockery::on(
            fn ($c) => $c['error'] === \Illuminate\Http\Client\ConnectionException::class && ! str_contains(json_encode($c), 'test-token')
        ));
    }

    public function test_large_batch_is_stored_and_message_cut_on_a_whole_line(): void
    {
        $body = implode("\n", array_map(fn ($i) => "{$i}\t2026-11-25 09:00:00\t0\t15", range(1, 200)));

        $this->postAttlog($body)->assertSeeText('OK: 200');

        $this->assertDatabaseCount('attendance_logs', 200);
        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) {
            return mb_strlen($r['text']) <= 4096
                && preg_match('/\n… and (\d+) more \(see the dashboard\)$/u', $r['text'], $m)
                && substr_count($r['text'], '• PIN') + (int) $m[1] === 200;
        });
    }

    public function test_no_telegram_message_when_not_configured(): void
    {
        config(['error-notifier.drivers.telegram.bot_token' => null]);

        $this->postAttlog("1001\t2026-11-25 09:00:00")->assertOk();

        $this->assertDatabaseCount('attendance_logs', 1);
        Http::assertNothingSent();
    }
}

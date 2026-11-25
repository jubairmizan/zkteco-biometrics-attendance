<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Tests\TestCase;

class EmployeeDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['error-notifier.drivers.telegram.bot_token' => null]);
        Http::fake();
        ErrorNotifier::spy();
    }

    private function device(string $method, string $uri, string $body = '')
    {
        return $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    // ---- Names from the device -------------------------------------------------------------

    public function test_user_records_in_the_operation_log_are_saved_and_secrets_dropped(): void
    {
        $body = implode("\n", [
            "USER PIN=1001\tName=Rahim Uddin\tPri=0\tPasswd=4321\tCard=[0012345678]\tGrp=1\tTZ=0000000000000000",
            "USER PIN=1002\tName=Karim\tPri=14\tPasswd=\tCard=[0000000000]\tGrp=1",
            "OPLOG 4\t0\t2026-11-25 09:00:00\t0\t0\t0\t0",
            "FP PIN=1001\tFID=6\tSize=1024\tValid=1\tTMP=SECRET-TEMPLATE-DATA",
        ]);

        $this->device('POST', '/iclock/cdata?SN=ABC123&table=OPERLOG&Stamp=1', $body)->assertOk()->assertSeeText('OK');

        $this->assertSame(2, Employee::count());
        $rahim = Employee::where('pin', '1001')->first();
        $this->assertSame('Rahim Uddin', $rahim->name);
        $this->assertSame('12345678', $rahim->card_number);
        $this->assertSame(0, $rahim->privilege);
        $this->assertSame('ABC123', $rahim->device_sn);
        $this->assertNull(Employee::where('pin', '1002')->first()->card_number);
        $this->assertTrue(Employee::where('pin', '1002')->first()->isDeviceAdmin());

        $stored = json_encode(Employee::all()->toArray());
        $this->assertStringNotContainsString('4321', $stored);
        $this->assertStringNotContainsString('SECRET-TEMPLATE-DATA', $stored);
    }

    public function test_querydata_user_reply_is_saved(): void
    {
        $body = "user uid=1\tcardno=99887766\tpin=2001\tpassword=\tgroup=1\tstarttime=0\tendtime=0\tname=Nusrat Jahan\tprivilege=0\n"
            . "user uid=2\tcardno=\tpin=2002\tpassword=\tgroup=1\tname=\tprivilege=0";

        $this->device('POST', '/iclock/querydata?SN=ABC123&type=tabledata&cmdid=1&tablename=user&count=2&packcnt=1&packidx=1', $body)
            ->assertOk()
            ->assertSeeText('user=2');

        $this->assertSame('Nusrat Jahan', Employee::where('pin', '2001')->value('name'));
        $this->assertSame('99887766', Employee::where('pin', '2001')->value('card_number'));
        $this->assertNull(Employee::where('pin', '2002')->value('name'));
    }

    public function test_other_querydata_tables_are_acknowledged_not_stored(): void
    {
        $this->device('POST', '/iclock/querydata?SN=ABC123&tablename=templatev10', "templatev10 pin=1\ttemplate=SECRET")
            ->assertOk()->assertSeeText('templatev10=0');

        $this->assertSame(0, Employee::count());
    }

    public function test_user_and_template_uploads_are_not_written_to_the_request_log(): void
    {
        $channel = \Mockery::spy();
        Log::shouldReceive('channel')->with('requests')->andReturn($channel);
        Log::shouldReceive('info', 'warning', 'error')->andReturnNull();

        $this->device('POST', '/iclock/cdata?SN=ABC123&table=OPERLOG', "USER PIN=1\tName=A\tPasswd=4321");
        $this->device('POST', '/iclock/querydata?SN=ABC123&tablename=user', "user pin=1\tpassword=4321");
        $this->device('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', "1\t2026-11-25 09:00:00");

        $channel->shouldHaveReceived('info')->with('POST /iclock/cdata 200', \Mockery::on(fn ($c) => str_contains($c['body'], 'not logged') && ! str_contains($c['body'], '4321')));
        $channel->shouldHaveReceived('info')->with('POST /iclock/querydata 200', \Mockery::on(fn ($c) => str_contains($c['body'], 'not logged')));
        $channel->shouldHaveReceived('info')->with('POST /iclock/cdata 200', \Mockery::on(fn ($c) => $c['body'] === "1\t2026-11-25 09:00:00"));
    }

    public function test_handshake_asks_for_user_details_but_not_templates(): void
    {
        $body = $this->get('/iclock/cdata?SN=ABC123&options=all')->getContent();

        $this->assertStringContainsString('OPERLOGStamp=None', $body);
        $this->assertStringContainsString('TransFlag=TransData AttLog OpLog EnrollUser ChgUser', $body);
        $this->assertStringNotContainsString('EnrollFP', $body);
    }

    // ---- Command queue ----------------------------------------------------------------------

    public function test_new_device_is_asked_for_its_users_once_and_result_is_recorded(): void
    {
        $this->get('/iclock/cdata?SN=ABC123&options=all');

        $command = DeviceCommand::firstOrFail();
        $this->assertSame(DeviceCommand::QUERY_USERS, $command->command);

        $this->get('/iclock/getrequest?SN=ABC123')->assertOk()->assertSeeText("C:{$command->id}:DATA QUERY USERINFO");
        $this->assertSame('sent', $command->fresh()->status);

        // Sent once only
        $this->get('/iclock/getrequest?SN=ABC123')->assertSeeText('OK');

        $this->device('POST', '/iclock/devicecmd?SN=ABC123', "ID={$command->id}&Return=0&CMD=DATA")->assertOk();
        $this->assertSame('done', $command->fresh()->status);
        $this->assertSame(0, $command->fresh()->return_code);

        // A known device reconnecting is not asked again
        $this->get('/iclock/cdata?SN=ABC123&options=all');
        $this->assertSame(1, DeviceCommand::count());
    }

    public function test_failed_command_and_other_devices_commands(): void
    {
        $mine = DeviceCommand::queue('ABC123', DeviceCommand::QUERY_USERS);
        $theirs = DeviceCommand::queue('OTHER', DeviceCommand::QUERY_USERS);

        $this->get('/iclock/getrequest?SN=ABC123')->assertSeeText("C:{$mine->id}:")->assertDontSeeText("C:{$theirs->id}:");

        $this->device('POST', '/iclock/devicecmd?SN=ABC123', "ID={$mine->id}&Return=-1002&CMD=DATA\nID={$theirs->id}&Return=0&CMD=DATA");

        $this->assertSame('failed', $mine->fresh()->status);
        $this->assertSame('pending', $theirs->fresh()->status); // a device can't close another device's command
    }

    // ---- Punches and names ------------------------------------------------------------------

    public function test_punching_creates_an_employee_row_and_dashboard_shows_name_and_department(): void
    {
        $this->device('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', "3001\t2026-11-25 09:00:00\t0\t15")->assertSeeText('OK: 1');
        $this->assertDatabaseHas('employees', ['pin' => '3001', 'name' => null]);

        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/attendance')->assertSee('No name yet');

        Employee::where('pin', '3001')->update(['name' => 'Farhana Akter', 'department' => 'Accounts', 'designation' => 'Officer']);

        $this->actingAs($admin)->get('/attendance')
            ->assertSee('Farhana Akter')
            ->assertSee('Officer · Accounts')
            ->assertSee(route('employees.show', Employee::first()));
        $this->actingAs($admin)->get('/attendance?search=accounts')->assertSee('Farhana Akter');
    }

    public function test_telegram_message_uses_employee_name(): void
    {
        config(['error-notifier.drivers.telegram.bot_token' => 't', 'error-notifier.drivers.telegram.chat_id' => '1']);
        Employee::create(['pin' => '1001', 'name' => 'Rahim Uddin']);

        $this->device('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', "1001\t2026-11-25 09:00:00\t0\t15");

        Http::assertSent(fn ($r) => str_contains($r['text'], '• Rahim Uddin (PIN 1001) | 2026-11-25 09:00:00 | Check-In | Face'));
    }

    // ---- Dashboard pages --------------------------------------------------------------------

    public function test_employee_pages_require_login(): void
    {
        $employee = Employee::create(['pin' => '1']);

        $this->get('/employees')->assertRedirect(route('login'));
        $this->get("/employees/{$employee->id}")->assertRedirect(route('login'));
        $this->put("/employees/{$employee->id}", ['name' => 'x'])->assertRedirect(route('login'));
        $this->post('/employees/fetch-from-devices')->assertRedirect(route('login'));
    }

    public function test_employee_list_and_search(): void
    {
        Employee::create(['pin' => '1001', 'name' => 'Rahim Uddin', 'department' => 'Sales']);
        Employee::create(['pin' => '1002', 'name' => 'Karim Ahmed', 'department' => 'Accounts']);
        Employee::create(['pin' => '1003']);
        AttendanceLog::create(['device_user_id' => '1001', 'device_ip' => 'x', 'punch_time' => now(), 'punch_type' => 'check_in']);

        $this->actingAs(User::factory()->create());

        $this->get('/employees')->assertOk()
            ->assertSeeInOrder(['Karim Ahmed', 'Rahim Uddin', 'No name yet']) // named first, alphabetical
            ->assertSee('1 punch(es)');
        $this->get('/employees?search=accounts')->assertSee('Karim Ahmed')->assertDontSee('Rahim Uddin');
        $this->get('/employees?search=1001')->assertSee('Rahim Uddin')->assertDontSee('Karim Ahmed');
    }

    public function test_employee_page_shows_details_and_daily_summary(): void
    {
        $employee = Employee::create([
            'pin' => '1001', 'name' => 'Rahim Uddin', 'department' => 'Sales', 'designation' => 'Manager',
            'phone' => '+8801700000000', 'card_number' => '12345678',
        ]);
        foreach ([['2026-11-24 09:02:00', 'check_in'], ['2026-11-24 13:00:00', 'break_out'], ['2026-11-24 17:32:00', 'check_out'], ['2026-11-25 08:55:00', 'check_in']] as [$time, $type]) {
            AttendanceLog::create(['device_user_id' => '1001', 'device_ip' => 'x', 'punch_time' => $time, 'punch_type' => $type]);
        }

        $this->actingAs(User::factory()->create())
            ->get("/employees/{$employee->id}?month=2026-11")
            ->assertOk()
            ->assertSee('Rahim Uddin')
            ->assertSee('Manager · Sales')
            ->assertSee('+8801700000000')
            ->assertSee('12345678')
            ->assertSee('November 2026')
            ->assertSeeInOrder(['Wed, Nov 25', '08:55', 'Tue, Nov 24', '09:02', '17:32', '8h 30m'])
            ->assertSee('2</div><div class="text-xs text-gray-500">days present', false)
            ->assertSee('All punches');
    }

    public function test_bad_month_is_rejected(): void
    {
        $employee = Employee::create(['pin' => '1']);

        $this->actingAs(User::factory()->create())
            ->get("/employees/{$employee->id}?month=2026-99")
            ->assertSessionHasErrors('month');
    }

    public function test_edited_name_survives_device_sync_and_clearing_it_hands_back(): void
    {
        $employee = Employee::syncFromDevice(['pin' => '1001', 'name' => 'RAHIM'], 'ABC123');
        $this->actingAs(User::factory()->create());

        $this->put("/employees/{$employee->id}", [
            'name' => 'Rahim Uddin', 'department' => 'Sales', 'designation' => 'Manager', 'email' => 'rahim@example.com',
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $employee->refresh();
        $this->assertSame('Rahim Uddin', $employee->name);
        $this->assertFalse($employee->name_from_device);

        Employee::syncFromDevice(['pin' => '1001', 'name' => 'RAHIM', 'card_number' => '555'], 'ABC123');
        $this->assertSame('Rahim Uddin', $employee->fresh()->name);
        $this->assertSame('555', $employee->fresh()->card_number);

        // Clearing the name gives it back to the device
        $this->put("/employees/{$employee->id}", ['name' => '', 'department' => 'Sales']);
        Employee::syncFromDevice(['pin' => '1001', 'name' => 'RAHIM'], 'ABC123');
        $this->assertSame('RAHIM', $employee->fresh()->name);
    }

    public function test_update_validates_email(): void
    {
        $employee = Employee::create(['pin' => '1']);

        $this->actingAs(User::factory()->create())
            ->put("/employees/{$employee->id}", ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    public function test_fetch_button_queues_one_command_per_device(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/employees/fetch-from-devices')->assertSessionHas('error');

        Device::create(['serial_number' => 'A']);
        Device::create(['serial_number' => 'B']);

        $this->post('/employees/fetch-from-devices')->assertSessionHas('success');
        $this->post('/employees/fetch-from-devices'); // pressing twice doesn't queue twice

        $this->assertSame(2, DeviceCommand::where('status', 'pending')->count());
        $this->get('/employees')->assertSee('Waiting for 2 device(s)');
    }
}

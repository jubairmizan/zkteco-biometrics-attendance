<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Msahidurr\ErrorNotifier\Facades\ErrorNotifier;
use Tests\TestCase;

class ZktecoAdmsTest extends TestCase
{
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
        $this->get('/iclock/getrequest?SN=ABC123')->assertOk()->assertSeeText('OK');
        $this->post('/iclock/devicecmd?SN=ABC123')->assertOk()->assertSeeText('OK');
    }

    public function test_handshake_returns_device_options(): void
    {
        $response = $this->get('/iclock/cdata?SN=ABC123&options=all');

        $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('GET OPTION FROM: ABC123', $response->getContent());
        $this->assertStringContainsString('ATTLOGStamp=None', $response->getContent());
        $this->assertStringContainsString('Realtime=1', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_punches_are_logged_and_sent_as_one_telegram_message(): void
    {
        Log::spy();

        $this->postAttlog("1001\t2026-11-25 09:00:00\t0\t15\t0\r\n1002\t2026-11-25 18:01:00\t1\t1\t0\r\n")
            ->assertOk()
            ->assertSeeText('OK: 2');

        Log::shouldHaveReceived('info')->with('ZKTeco Punch', \Mockery::on(fn ($c) => $c['pin'] === '1001' && $c['sn'] === 'ABC123'));
        Log::shouldHaveReceived('info')->with('ZKTeco Punch', \Mockery::on(fn ($c) => $c['pin'] === '1002' && $c['status'] === '1'));

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

    public function test_other_status_and_verify_codes_are_labelled(): void
    {
        $this->postAttlog("7\t2026-11-25 12:00:00\t2\t4\t0\n8\t2026-11-25 13:00:00\t3\t0\t0")->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'PIN 7 | 2026-11-25 12:00:00 | Break-Out | Mode 4')
            && str_contains($r['text'], 'PIN 8 | 2026-11-25 13:00:00 | Break-In | Password'));
    }

    public function test_pin_zero_is_not_treated_as_malformed(): void
    {
        $this->postAttlog("0\t2026-11-25 09:00:00\t0\t1")->assertSeeText('OK: 1');

        ErrorNotifier::shouldNotHaveReceived('report');
    }

    public function test_missing_optional_columns_use_defaults(): void
    {
        $this->postAttlog("1001\t2026-11-25 09:00:00")->assertSeeText('OK: 1');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'Check-In | Face'));
    }

    public function test_malformed_line_is_reported_and_rest_of_batch_is_kept(): void
    {
        $this->postAttlog("garbage\n1001\t2026-11-25 09:00:00\t0\t15")->assertOk()->assertSeeText('OK: 1');

        ErrorNotifier::shouldHaveReceived('report')->once()
            ->with(\Mockery::on(fn ($e) => $e instanceof \UnexpectedValueException && str_contains($e->getMessage(), 'garbage')));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'PIN 1001'));
    }

    public function test_empty_body_sends_nothing(): void
    {
        $this->postAttlog('')->assertOk()->assertSeeText('OK: 0');

        Http::assertNothingSent();
        ErrorNotifier::shouldNotHaveReceived('report');
    }

    public function test_other_tables_are_acknowledged_and_ignored(): void
    {
        $this->postAttlog("OPLOG 4\t0\t2026-11-25 09:00:00\t0\t0\t0\t0", 'SN=ABC123&table=OPERLOG')
            ->assertOk()->assertSeeText('OK');

        Http::assertNothingSent();
        ErrorNotifier::shouldNotHaveReceived('report');
    }

    public function test_missing_or_array_serial_number_does_not_crash(): void
    {
        $this->postAttlog("1001\t2026-11-25 09:00:00", 'table=ATTLOG')->assertOk();
        $this->postAttlog("1001\t2026-11-25 09:00:00", 'SN[]=x&table=ATTLOG')->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r['text'], 'ZKTeco Punch (device unknown)'));
    }

    public function test_telegram_failure_still_returns_ok_and_does_not_log_token(): void
    {
        $this->telegram = fn () => Http::response(['ok' => false], 500);
        Log::spy();

        $this->postAttlog("1001\t2026-11-25 09:00:00")->assertOk()->assertSeeText('OK: 1');

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

    public function test_large_batch_is_cut_on_a_whole_line_with_a_count(): void
    {
        $body = implode("\n", array_map(fn ($i) => "{$i}\t2026-11-25 09:00:00\t0\t15", range(1, 200)));

        $this->postAttlog($body)->assertSeeText('OK: 200');

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) {
            return mb_strlen($r['text']) <= 4096
                && preg_match('/\n… and (\d+) more \(see laravel\.log\)$/u', $r['text'], $m)
                && substr_count($r['text'], '• PIN') + (int) $m[1] === 200;
        });
    }

    public function test_no_telegram_message_when_not_configured(): void
    {
        config(['error-notifier.drivers.telegram.bot_token' => null]);

        $this->postAttlog("1001\t2026-11-25 09:00:00")->assertOk();

        Http::assertNothingSent();
    }
}

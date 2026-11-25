<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogIncomingRequestsTest extends TestCase
{
    use RefreshDatabase;

    private \Mockery\MockInterface $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->channel = \Mockery::spy();
        Log::shouldReceive('channel')->with('requests')->andReturn($this->channel);
        Log::shouldReceive('info', 'warning', 'error')->andReturnNull();
    }

    public function test_device_request_is_logged_with_body_and_response(): void
    {
        $this->call('POST', '/iclock/cdata?SN=ABC123&table=ATTLOG', [], [], [], [], "1001\t2026-11-25 09:00:00\t0\t15");

        $this->channel->shouldHaveReceived('info')->once()->with('POST /iclock/cdata 200', \Mockery::on(fn ($c) =>
            $c['url'] === 'http://localhost/iclock/cdata?SN=ABC123&table=ATTLOG'
            && $c['body'] === "1001\t2026-11-25 09:00:00\t0\t15"
            && $c['response'] === "OK: 1\n"
            && is_int($c['duration_ms'])
        ));
    }

    public function test_other_requests_are_logged_without_body(): void
    {
        $this->post('/login', ['email' => 'a@b.c', 'password' => 'secret-pass']);

        $this->channel->shouldHaveReceived('info')->once()->with(\Mockery::pattern('#^POST /login \d{3}$#'), \Mockery::on(fn ($c) =>
            ! array_key_exists('body', $c) && ! str_contains(json_encode($c), 'secret-pass')
        ));
    }

    public function test_unknown_routes_are_logged_too(): void
    {
        $this->get('/api/iclock/cdata?SN=ABC123');

        $this->channel->shouldHaveReceived('info')->with('GET /api/iclock/cdata 404', \Mockery::any());
    }

    public function test_can_be_turned_off(): void
    {
        config(['logging.log_http_requests' => false]);

        $this->get('/iclock/getrequest?SN=ABC123');

        $this->channel->shouldNotHaveReceived('info');
    }
}

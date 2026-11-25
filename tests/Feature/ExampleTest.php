<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        // The dashboard requires login, so a guest is sent to the login page
        $response->assertRedirect(route('attendance.index'));
        $this->get(route('attendance.index'))->assertRedirect(route('login'));
    }
}

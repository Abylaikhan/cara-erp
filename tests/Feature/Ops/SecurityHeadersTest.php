<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every response carries the security headers (ADR-0031): pages, a missing page, the health check, a JSON answer. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertSecure($response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_pages_error_pages_and_the_health_check_carry_the_headers(): void
    {
        $this->assertSecure($this->get('/login')->assertOk());
        $this->assertSecure($this->get('/no-such-page-at-all')->assertNotFound());
        $this->assertSecure($this->get('/up')->assertOk());
        $this->assertSecure($this->get('/dashboard')->assertRedirect('/login'));

        $director = User::factory()->role('director')->create();
        $this->assertSecure($this->actingAs($director)->get('/dashboard')->assertOk());
        $this->assertSecure($this->actingAs($director)->getJson('/suppliers/counterparties')->assertOk());
    }
}

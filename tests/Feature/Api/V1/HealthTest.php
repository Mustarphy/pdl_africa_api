<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_only_a_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_api_responses_carry_security_headers_and_rate_limit(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-RateLimit-Limit', '120');
    }

    public function test_cors_allows_only_the_configured_frontend_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:5173'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');

        // With a single allowed origin the header always names that origin,
        // so browsers on any other origin reject the response.
        $foreign = $this->getJson('/api/v1/health', ['Origin' => 'https://evil.example']);
        $this->assertNotSame('https://evil.example', $foreign->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $foreign->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_never_echoes_an_unlisted_origin_with_several_allowed(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://shop.example']]);

        $this->getJson('/api/v1/health', ['Origin' => 'https://shop.example'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example');

        $this->getJson('/api/v1/health', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_cors_preflight_is_answered_for_allowed_origin(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->call('OPTIONS', '/api/v1/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'accept, x-requested-with',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            // Credentials are enabled for the admin cookie session (explicit origins only).
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unknown_api_routes_return_json_404(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_errors_reveal_no_internals_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/__test-failure', fn () => throw new RuntimeException('secret detail from /var/www'));

        $response = $this->getJson('/api/v1/__test-failure')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Server Error']);

        $this->assertStringNotContainsString('secret detail', $response->getContent());
    }

    public function test_api_is_rate_limited(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/health')->assertOk();
        }

        $this->getJson('/api/v1/health')->assertStatus(429);
    }
}

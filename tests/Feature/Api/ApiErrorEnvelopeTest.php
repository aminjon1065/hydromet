<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\ApiRequestId;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ThrowsHttpResponse;
use Tests\TestCase;

class ApiErrorEnvelopeTest extends TestCase
{
    #[Test]
    public function a_rate_limited_request_keeps_the_headers_a_client_needs_to_back_off(): void
    {
        Route::middleware([ApiRequestId::class, 'throttle:1,1'])
            ->get('api/v1/testing/throttled', fn (): array => ['ok' => true]);

        $this->getJson('/api/v1/testing/throttled')->assertOk();

        $response = $this->getJson('/api/v1/testing/throttled')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function an_unsupported_method_keeps_its_allow_header(): void
    {
        $response = $this->postJson('/api/v1/metadata')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed');

        $this->assertStringContainsString('GET', (string) $response->headers->get('Allow'));
    }

    /**
     * A response thrown inside a route action is returned by the router itself,
     * so the middleware stack is the path on which one actually reaches the
     * exception handler.
     */
    #[Test]
    public function a_thrown_failing_response_becomes_an_http_failure_without_leaking_its_headers(): void
    {
        Route::get('api/v1/testing/thrown-response', fn (): array => ['unreachable' => true])
            ->middleware([ApiRequestId::class, ThrowsHttpResponse::class.':failing']);

        $response = $this->getJson('/api/v1/testing/thrown-response')
            ->assertStatus(418)
            ->assertJsonPath('error.code', 'http_error');

        $this->assertSame('5', $response->headers->get('X-RateLimit-Limit'));
        $this->assertNull($response->headers->get('X-Upstream-Host'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('upstream said no', $response->getContent() ?: '');
    }

    #[Test]
    public function a_thrown_successful_response_is_not_turned_into_an_error(): void
    {
        Route::get('api/v1/testing/thrown-accepted', fn (): array => ['unreachable' => true])
            ->middleware([ApiRequestId::class, ThrowsHttpResponse::class.':successful']);

        $this->getJson('/api/v1/testing/thrown-accepted')
            ->assertStatus(202)
            ->assertExactJson(['accepted' => true]);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ApiRequestId;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

/**
 * An unhandled failure must describe an HTTP outcome and nothing else.
 *
 * The exception message here carries the shapes that leak in practice — a
 * connection string with a password, a bearer token and a filesystem path — so
 * the assertions fail loudly if the envelope ever starts echoing what it caught.
 */
class FailureDisclosureTest extends TestCase
{
    private const SECRET_MESSAGE = 'pgsql://hydromet:s3cr3t@10.0.0.5/hydromet failed; token=Bearer abc.def.ghi';

    private function registerFailingApiRoute(): void
    {
        Route::middleware([ApiRequestId::class])
            ->get('api/v1/testing/explodes', function (): never {
                throw new RuntimeException(self::SECRET_MESSAGE);
            });
    }

    /**
     * Debug is forced on, because that is the configuration in which a leak
     * would actually happen. If the envelope holds here it holds in production.
     */
    #[Test]
    #[TestWith([true])]
    #[TestWith([false])]
    public function an_unexpected_api_failure_never_returns_the_exception(bool $debug): void
    {
        config(['app.debug' => $debug]);
        $this->registerFailingApiRoute();

        $response = $this->getJson('/api/v1/testing/explodes')->assertStatus(500);

        $response->assertExactJsonStructure([
            'error' => ['code', 'message', 'details', 'request_id'],
        ]);
        $response->assertJsonPath('error.code', 'internal_error');
        $response->assertJsonPath('error.message', 'The request could not be completed.');

        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('s3cr3t', $body);
        $this->assertStringNotContainsString('Bearer', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('vendor', $body);
        $this->assertStringNotContainsString('FailureDisclosureTest', $body);
    }

    /**
     * The request id is the whole diagnostic channel: it correlates the safe
     * public response with the full detail in the server log.
     */
    #[Test]
    public function the_failure_is_correlatable_through_the_request_id(): void
    {
        $this->registerFailingApiRoute();

        $response = $this->getJson('/api/v1/testing/explodes')->assertStatus(500);

        $this->assertSame(
            $response->json('error.request_id'),
            $response->headers->get('X-Request-Id'),
        );
    }

    /**
     * A failing response must not be cached by a proxy and handed to the next
     * visitor, and it must not be sniffed into something executable.
     */
    #[Test]
    public function a_failing_response_is_not_cacheable_and_is_not_sniffable(): void
    {
        $this->registerFailingApiRoute();

        $response = $this->getJson('/api/v1/testing/explodes')->assertStatus(500);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}

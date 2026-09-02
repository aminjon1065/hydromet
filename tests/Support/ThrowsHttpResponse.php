<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test middleware that throws a prepared response.
 *
 * Middleware is the path on which an `HttpResponseException` actually reaches
 * the exception handler: one thrown inside a route action is caught and
 * returned by `Illuminate\Routing\Route::run()` instead.
 */
class ThrowsHttpResponse
{
    public function handle(Request $request, Closure $next, string $scenario): Response
    {
        throw new HttpResponseException(match ($scenario) {
            'failing' => response('upstream said no', 418)
                ->header('Content-Type', 'text/plain')
                ->header('X-RateLimit-Limit', '5')
                ->header('X-Upstream-Host', 'smartmet.internal')
                ->cookie('provider_session', 'secret'),
            'successful' => response()->json(['accepted' => true], 202),
            default => throw new InvalidArgumentException("Unknown scenario [{$scenario}]."),
        });
    }
}

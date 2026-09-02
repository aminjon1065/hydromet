<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::ulid();
        $request->attributes->set('api_request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}

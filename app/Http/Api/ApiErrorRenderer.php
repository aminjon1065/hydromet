<?php

namespace App\Http\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Converts every `/api/*` failure into the envelope fixed in the API contract.
 */
final class ApiErrorRenderer
{
    /**
     * Headers a client needs in order to react correctly to the failure.
     *
     * Everything else the failing response carried is dropped: cookies, content
     * negotiation and any header an upstream adapter may have attached.
     *
     * @var array<int, string>
     */
    private const PROTOCOL_HEADERS = ['allow', 'retry-after', 'www-authenticate'];

    /** @var array<int, string> */
    private const PROTOCOL_HEADER_PREFIXES = ['x-ratelimit-'];

    /**
     * A deliberate successful response thrown as control flow is not a failure,
     * so it must reach the client unchanged rather than through the envelope.
     */
    public function handles(Throwable $exception): bool
    {
        return ! $exception instanceof HttpResponseException
            || $exception->getResponse()->getStatusCode() >= 400;
    }

    public function render(Throwable $exception, Request $request): JsonResponse
    {
        $requestId = $request->attributes->get('api_request_id');

        if (! is_string($requestId)) {
            $requestId = (string) Str::ulid();
        }

        [$status, $code, $message, $details] = $this->describe($exception);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? (object) [] : $details,
                'request_id' => $requestId,
            ],
        ], $status, [
            ...$this->protocolHeaders($exception),
            'Cache-Control' => 'no-store',
            'X-Request-Id' => $requestId,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function protocolHeaders(Throwable $exception): array
    {
        $candidates = match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getHeaders(),
            $exception instanceof HttpResponseException => $exception->getResponse()->headers->all(),
            default => [],
        };

        $preserved = [];

        foreach ($candidates as $name => $value) {
            if (! is_string($name) || ! $this->isProtocolHeader($name)) {
                continue;
            }

            $value = is_array($value) ? reset($value) : $value;

            if (is_string($value) || is_int($value)) {
                $preserved[$name] = (string) $value;
            }
        }

        return $preserved;
    }

    private function isProtocolHeader(string $name): bool
    {
        $name = strtolower($name);

        if (in_array($name, self::PROTOCOL_HEADERS, true)) {
            return true;
        }

        foreach (self::PROTOCOL_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{int, string, string, array<string, mixed>}
     */
    private function describe(Throwable $exception): array
    {
        if ($exception instanceof ApiProblem) {
            return [
                $exception->getStatusCode(),
                $exception->problemCode,
                $exception->getMessage(),
                $exception->details,
            ];
        }

        if ($exception instanceof ValidationException) {
            return [422, 'validation_failed', 'One or more query parameters are invalid.', [
                'fields' => $exception->errors(),
            ]];
        }

        if ($exception instanceof ModelNotFoundException) {
            return [404, 'not_found', 'The requested resource was not found.', []];
        }

        if ($exception instanceof AuthenticationException) {
            return [401, 'unauthenticated', 'Authentication is required.', []];
        }

        if ($exception instanceof AuthorizationException) {
            return [403, 'forbidden', 'This operation is not permitted.', []];
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->httpProblem($exception->getStatusCode());
        }

        // Control flow that carries its own failing response still describes an
        // HTTP outcome; reporting it as an internal error would mislead clients.
        if ($exception instanceof HttpResponseException) {
            return $this->httpProblem($exception->getResponse()->getStatusCode());
        }

        return [500, 'internal_error', 'The request could not be completed.', []];
    }

    /**
     * @return array{int, string, string, array<string, mixed>}
     */
    private function httpProblem(int $status): array
    {
        return match ($status) {
            400 => [400, 'bad_request', 'The request is invalid.', []],
            401 => [401, 'unauthenticated', 'Authentication is required.', []],
            403 => [403, 'forbidden', 'This operation is not permitted.', []],
            404 => [404, 'not_found', 'The requested resource was not found.', []],
            405 => [405, 'method_not_allowed', 'The HTTP method is not supported.', []],
            409 => [409, 'conflict', 'The request conflicts with current state.', []],
            422 => [422, 'unprocessable_request', 'The request cannot be processed.', []],
            429 => [429, 'rate_limited', 'Too many requests.', []],
            default => [$status >= 400 && $status < 600 ? $status : 500, 'http_error', 'The request could not be completed.', []],
        };
    }
}

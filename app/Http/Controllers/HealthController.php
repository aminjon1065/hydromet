<?php

namespace App\Http\Controllers;

use App\Support\Health\ReadinessCheck;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class HealthController extends Controller
{
    /**
     * Readiness probe used by container health checks and monitoring.
     *
     * `/up` stays the liveness probe: it only proves the framework booted.
     * This endpoint additionally proves the database and the cache store are
     * reachable, so a stopped dependency fails readiness (docs/02-architecture.md,
     * section 7).
     */
    public function __invoke(ReadinessCheck $check): JsonResponse
    {
        $report = $check->run();

        return response()
            ->json([
                ...$report,
                'generated_at' => now()->utc()->toIso8601ZuluString(),
            ], $report['status'] === ReadinessCheck::STATUS_OK
                ? Response::HTTP_OK
                : Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Cache-Control', 'no-store');
    }
}

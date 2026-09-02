<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Queries\PublicSystemStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/system/status`, docs/05-api-contract.md section 2.
 *
 * Whether the portal's copy of each external source is current. It is not a
 * health check of the application — `/up` and `/health` answer that, and a
 * monitoring system should keep watching those — and it is not a health check
 * of Hydromet, which the portal cannot see.
 *
 * The response is never cached: a status that is one minute out of date is
 * worse than no status, because it would say a stale source is current.
 *
 * All of the reasoning lives in {@see PublicSystemStatus}; this only hands the
 * report to the client.
 */
class SystemStatusController extends Controller
{
    public function __invoke(PublicSystemStatus $status): JsonResponse
    {
        return response()
            ->json($status->report())
            ->withHeaders(['Cache-Control' => 'no-store']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\Queries\PublicAlertOverview;
use App\Http\Api\ApiProblem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/alerts/{source}/{identifier}`, docs/05-api-contract.md section 2.
 *
 * A CAP identifier is unique within its sender, not globally, so the public
 * identity of a warning is the pair `(source, identifier)` — the same pair the
 * storage layer keys on, and the same pair every entry of the list endpoint
 * already carries. Both segments therefore come from the URL: resolving the
 * source from configuration instead would make every warning from a second
 * feed unreachable, which is exactly the defect this replaced.
 *
 * Unlike the list, this returns a warning that is no longer in force: a client
 * that stored an identifier must still be able to explain what happened to it,
 * which is the whole reason the message chain is kept. `is_active` and
 * `superseded_at` say which state the message is in.
 *
 * A non-public message is reported as not found rather than as forbidden: its
 * existence is itself not public, and distinguishing the two would let anyone
 * enumerate restricted identifiers.
 */
class AlertShowController extends Controller
{
    public function __invoke(string $source, string $identifier, PublicAlertOverview $overview): JsonResponse
    {
        $detail = $overview->detail(source: $source, identifier: $identifier);

        if ($detail === null) {
            throw new ApiProblem(404, 'not_found', 'No public warning exists for that source and identifier.');
        }

        $current = $detail['current'];

        return response()->json([
            'data' => [
                'identifier' => $current['identifier'],
                'source' => $current['source'],
                'is_mock' => $current['isMock'],
                'is_active' => $current['isActive'],
                'status' => $current['status'],
                'message_type' => $current['messageType'],
                'event_code' => $current['eventCode'],
                'severity' => $current['severity'],
                'urgency' => $current['urgency'],
                'certainty' => $current['certainty'],
                'sender' => $current['sender'],
                'headline' => $current['headline'],
                'description' => $current['description'],
                'instruction' => $current['instruction'],
                'sent_at' => $current['sentAt'],
                'effective_at' => $current['effectiveAt'],
                'onset_at' => $current['onsetAt'],
                'expires_at' => $current['expiresAt'],
                'superseded_at' => $current['supersededAt'],
                'areas' => array_map(static fn (array $area): array => [
                    'description' => $area['description'],
                    'geocodes' => $area['geocodes'],
                    'geometry' => $area['geometry'],
                ], $current['areas']),
                'history' => array_map(static fn (array $entry): array => [
                    'identifier' => $entry['identifier'],
                    'message_type' => $entry['messageType'],
                    'severity' => $entry['severity'],
                    'headline' => $entry['headline'],
                    'sent_at' => $entry['sentAt'],
                    'superseded_at' => $entry['supersededAt'],
                ], $detail['history']),
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Accept-Language',
        ]);
    }
}

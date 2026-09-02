<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Alerts\Queries\PublicAlertOverview;
use App\Http\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Support\Canonical\CanonicalReader;
use App\Support\Canonical\InvalidCanonicalRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/alerts`, docs/05-api-contract.md section 2.
 *
 * Returns only warnings in force: `Actual` + `Public`, not superseded by an
 * Update or Cancel, and inside their validity window. That default is the whole
 * point of the endpoint — a client showing a withdrawn or expired warning is
 * worse than one showing none.
 *
 * Two filters named in the contract are deliberately absent:
 *   - `include_test`, because publishing a `Test` message is a publication-rule
 *     decision Hydromet has not made
 *     (docs/08-hydromet-input-checklist.md, section 3);
 *   - `region`, because the official region-code vocabulary is not agreed, and
 *     a geocode-containment query would behave differently on PostgreSQL and
 *     SQLite. `bbox` covers the map's actual need in the meantime.
 */
class AlertIndexController extends Controller
{
    public function __invoke(Request $request, PublicAlertOverview $overview): JsonResponse
    {
        $validated = $request->validate([
            'active_at' => ['nullable', 'string', 'max:40'],
            'bbox' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', Rule::enum(AlertSeverity::class)],
            'event_code' => ['nullable', 'string', 'max:64'],
        ]);

        $moment = isset($validated['active_at'])
            ? $this->dateTime($validated['active_at'], 'active_at')
            : Carbon::now('UTC');

        $alerts = $overview->active(
            moment: $moment,
            bbox: isset($validated['bbox']) ? $this->bbox($validated['bbox']) : null,
            severity: $validated['severity'] ?? null,
            eventCode: $validated['event_code'] ?? null,
        );

        $data = array_map(static fn (array $alert): array => [
            'identifier' => $alert['identifier'],
            'source' => $alert['source'],
            'is_mock' => $alert['isMock'],
            'event_code' => $alert['eventCode'],
            'severity' => $alert['severity'],
            'urgency' => $alert['urgency'],
            'certainty' => $alert['certainty'],
            'sender' => $alert['sender'],
            'headline' => $alert['headline'],
            'description' => $alert['description'],
            'instruction' => $alert['instruction'],
            'sent_at' => $alert['sentAt'],
            'effective_at' => $alert['effectiveAt'],
            'onset_at' => $alert['onsetAt'],
            'expires_at' => $alert['expiresAt'],
            'areas' => array_map(static fn (array $area): array => [
                'description' => $area['description'],
                'geocodes' => $area['geocodes'],
                'geometry' => $area['geometry'],
            ], $alert['areas']),
        ], $alerts);

        return response()->json([
            'data' => $data,
            'meta' => [
                'generated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'active_at' => $moment->utc()->format('Y-m-d\TH:i:s.u\Z'),
                // Severity colours are a portal display choice, not a national
                // scale, so a client is told the ranking and nothing more.
                'severity_order' => AlertSeverity::descendingRankValues(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Accept-Language',
        ]);
    }

    /**
     * @return array{west: float, south: float, east: float, north: float}
     */
    private function bbox(string $value): array
    {
        $parts = explode(',', $value);

        if (count($parts) !== 4) {
            throw $this->invalidBbox();
        }

        $numbers = array_map(function (string $part): float {
            $trimmed = trim($part);

            if ($trimmed === '' || ! is_numeric($trimmed)) {
                throw $this->invalidBbox();
            }

            return (float) $trimmed;
        }, $parts);
        [$west, $south, $east, $north] = $numbers;

        if ($west < -180 || $east > 180 || $south < -90 || $north > 90 || $west > $east || $south > $north) {
            throw $this->invalidBbox();
        }

        return ['west' => $west, 'south' => $south, 'east' => $east, 'north' => $north];
    }

    private function invalidBbox(): ApiProblem
    {
        return new ApiProblem(
            422,
            'invalid_bbox',
            'The bbox must be west,south,east,north with valid ordered coordinates.',
            ['field' => 'bbox'],
        );
    }

    private function dateTime(string $value, string $field): Carbon
    {
        try {
            return (new CanonicalReader([$field => $value]))->dateTime($field);
        } catch (InvalidCanonicalRow) {
            throw new ApiProblem(
                422,
                'invalid_datetime',
                "The {$field} query parameter must be an ISO 8601 timestamp with an explicit timezone.",
                ['field' => $field],
            );
        }
    }
}

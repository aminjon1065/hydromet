<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Queries\PublicAlertOverview;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/alerts/history`, docs/05-api-contract.md section 2.
 *
 * Every warning the portal has published, newest first — including the ones
 * that have expired or been withdrawn.
 *
 * `/api/v1/alerts` answers a different question: what is in force now. That
 * default is deliberate there, because a client showing a withdrawn warning is
 * worse than one showing none. It does mean the API had no way to look back: a
 * past warning was reachable only by a client that already knew its identifier,
 * while the portal's own history page could list them all.
 *
 * The publication rule is the same one both other surfaces use — a message is
 * public when it is `Actual` and `Public` — so this endpoint cannot become a
 * way to read what the others hide. That matters more here than anywhere else,
 * because this is the surface that deliberately returns what is no longer
 * current.
 *
 * Unfiltered, for the reasons the contract already records: `region` needs an
 * agreed vocabulary, `from`/`to` need the feed's refresh semantics, and
 * `include_test` is a publication decision Hydromet has not made.
 */
class AlertHistoryController extends Controller
{
    /**
     * Warnings per page.
     *
     * A hundred, matching `GET /api/v1/stations` rather than the twenty the web
     * page shows: a person scrolls cards, a client assembles a dataset, and the
     * two have no reason to agree.
     */
    private const PAGE_SIZE = 100;

    public function __invoke(Request $request, PublicAlertOverview $overview): JsonResponse
    {
        // The cursor is opaque and comes from the client, so its length is the
        // whole validation available; its contents are Laravel's business, and
        // a malformed one simply yields the first page.
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        $page = $overview->historyPage($validated['cursor'] ?? null, self::PAGE_SIZE);

        $data = array_map(
            function (AlertMessage $message) use ($overview): array {
                $row = $overview->presentHistoryRow($message);

                return [
                    'identifier' => $row['identifier'],
                    'source' => $row['source'],
                    'is_mock' => $row['isMock'],
                    'message_type' => $row['messageType'],
                    'severity' => $row['severity'],
                    'headline' => $row['headline'],
                    'sent_at' => $row['sentAt'],
                    'effective_at' => $row['effectiveAt'],
                    'expires_at' => $row['expiresAt'],
                    // Together these say what happened to a warning a client
                    // stored earlier: still in force, simply expired, or
                    // replaced at a stated moment.
                    'superseded_at' => $row['supersededAt'],
                    'is_active' => $row['isActive'],
                    // Names only. The list carries no geometry, description or
                    // instruction; a client that needs them asks for the one
                    // warning, and a page of polygons would otherwise be paid
                    // for on every request.
                    'areas' => $row['areas'],
                ];
            },
            $page->items(),
        );

        return response()->json([
            'data' => array_values($data),
            'meta' => [
                'generated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Accept-Language',
        ]);
    }
}

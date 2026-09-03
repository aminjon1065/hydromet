<?php

namespace App\Http\Controllers;

use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Alerts\Queries\PublicAlertOverview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every warning the portal has published, newest first.
 *
 * The overview shows what is in force and drops a warning the moment it expires
 * or is withdrawn — which is right for a front page and wrong for anybody
 * asking what was issued last week. Its own empty state has been promising that
 * those warnings "remain available through the warning history"; this is the
 * list that keeps the promise, and the way to reach a warning without already
 * knowing its identifier.
 *
 * Unfiltered on purpose. A region filter needs an approved region vocabulary, a
 * date range needs the feed's refresh semantics, and whether a `Test` message
 * may ever be published is a Hydromet decision — all three are open
 * (`docs/05-api-contract.md`, section 2). Adding any of them would mean
 * inventing the answer. Chronological order needs nobody's approval.
 */
class AlertIndexController extends Controller
{
    public function __invoke(Request $request, PublicAlertOverview $overview): Response
    {
        // The cursor is opaque and comes from the URL. Bounding its length is
        // the whole validation available: its contents are Laravel's business,
        // and a malformed one simply yields the first page.
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        $page = $overview->historyPage($validated['cursor'] ?? null);

        return Inertia::render('alerts/index', [
            'alerts' => array_values(array_map(
                fn (AlertMessage $message): array => $overview->presentHistoryRow($message),
                $page->items(),
            )),
            // Named for the direction a reader moves rather than for the
            // mechanism: on a list that runs newest to oldest, "next" is
            // ambiguous and "older" is not.
            'older' => $page->nextCursor()?->encode(),
            'newer' => $page->previousCursor()?->encode(),
        ])->toResponse($request)->setCache([
            // Private because the rendered language comes from the session, and
            // short because whether a warning is still in force changes with the
            // clock rather than with a request.
            'private' => true,
            'max_age' => 60,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Alerts\Queries\PublicAlertOverview;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * One warning, in full, at a URL that can be shared.
 *
 * The home page lists the warnings in force and draws their areas, which
 * answers "is anything happening near me". It cannot answer the question a
 * person asks next — what exactly was issued, what am I told to do, and is this
 * still the current version — and its own empty state promises that withdrawn
 * and expired warnings "remain available through the warning history". This is
 * the page that makes that true.
 *
 * Deliberately thin. Every rule about what the public may see already lives in
 * {@see PublicAlertOverview::detail()}, which the API endpoint uses too, so the
 * two surfaces cannot drift apart: the same message is public here exactly when
 * it is public there.
 *
 * Unlike the list, this serves a warning that is no longer in force. That is
 * the point of a permalink — somebody following a link from an hour ago, or
 * from a message someone forwarded, must be told that it expired or was
 * superseded, not shown a 404 that reads as "nothing was ever wrong".
 */
class AlertController extends Controller
{
    public function __invoke(string $source, string $identifier, PublicAlertOverview $overview): Response
    {
        $detail = $overview->detail(source: $source, identifier: $identifier);

        if ($detail === null) {
            // Not found rather than forbidden, matching the API: the existence
            // of a restricted or test message is itself not public, and
            // distinguishing the two cases would let anyone enumerate them.
            abort(404);
        }

        return Inertia::render('alerts/show', [
            'alert' => $detail['current'],
            'history' => $detail['history'],
        ])->toResponse(request())->setCache([
            // Private because the rendered language comes from the session, and
            // short because a warning's `is_active` changes with the clock
            // rather than with a request.
            'private' => true,
            'max_age' => 60,
        ]);
    }
}

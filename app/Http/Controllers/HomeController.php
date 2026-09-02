<?php

namespace App\Http\Controllers;

use App\Domain\Alerts\Queries\PublicAlertOverview;
use App\Domain\Stations\Queries\PublicStationOverview;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(PublicStationOverview $overview, PublicAlertOverview $alerts): Response
    {
        return Inertia::render('home', [
            'generatedAt' => now()->utc()->toIso8601ZuluString(),
            'stations' => $overview->get(),
            // Warnings and stations are read independently: a warning source
            // that has nothing to say must never blank the station map.
            'alerts' => $alerts->active(),
        ]);
    }
}

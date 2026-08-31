<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('home', [
            'generatedAt' => now()->utc()->toIso8601ZuluString(),
        ]);
    }
}

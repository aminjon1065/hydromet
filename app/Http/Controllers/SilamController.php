<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SilamController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('silam', [
            'silamUrl' => (string) config('services.silam.url'),
        ]);
    }
}

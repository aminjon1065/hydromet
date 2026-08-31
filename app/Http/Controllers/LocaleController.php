<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\Locale\SupportedLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Store the visitor's explicit language choice.
     *
     * The locale is validated against the application locale keys, so an
     * arbitrary value from the URL can never reach the translator or the
     * session.
     */
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $selected = SupportedLocale::tryFrom($locale) ?? SupportedLocale::fallback();

        $request->session()->put(SetLocale::SESSION_KEY, $selected->value);

        return back();
    }
}

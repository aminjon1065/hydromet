<?php

namespace App\Http\Middleware;

use App\Support\Locale\SupportedLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale from, in order of precedence:
 *
 * 1. the locale the visitor explicitly chose (stored in the session);
 * 2. the `Accept-Language` header, accepting `tg` / `tg-TJ` as aliases of `tj`;
 * 3. the configured fallback locale (`ru`).
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromSession($request)
            ?? $this->fromHeader($request)
            ?? SupportedLocale::fallback();

        app()->setLocale($locale->value);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale->bcp47());

        return $response;
    }

    private function fromSession(Request $request): ?SupportedLocale
    {
        if (! $request->hasSession()) {
            return null;
        }

        $stored = $request->session()->get(self::SESSION_KEY);

        return is_string($stored) ? SupportedLocale::tryFrom($stored) : null;
    }

    private function fromHeader(Request $request): ?SupportedLocale
    {
        foreach ($request->getLanguages() as $language) {
            $locale = SupportedLocale::resolve($language);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }
}

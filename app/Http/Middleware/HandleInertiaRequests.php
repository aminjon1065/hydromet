<?php

namespace App\Http\Middleware;

use App\Support\Locale\SupportedLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = SupportedLocale::current();

        return [
            ...parent::share($request),
            'locale' => [
                'current' => $locale->value,
                'bcp47' => $locale->bcp47(),
                'fallback' => SupportedLocale::fallback()->value,
                'available' => array_map(
                    fn (SupportedLocale $available): array => [
                        'value' => $available->value,
                        'label' => $available->nativeName(),
                    ],
                    SupportedLocale::cases(),
                ),
            ],
            'displayTimezone' => (string) config('app.display_timezone'),
            'translations' => $this->translations($locale),
        ];
    }

    /**
     * Public UI strings for the active locale, with the fallback locale
     * merged underneath so a missing translation never renders a key.
     *
     * @return array<string, string>
     */
    private function translations(SupportedLocale $locale): array
    {
        $fallback = Lang::get('site', [], SupportedLocale::fallback()->value);
        $current = Lang::get('site', [], $locale->value);

        return [
            ...is_array($fallback) ? $fallback : [],
            ...is_array($current) ? $current : [],
        ];
    }
}

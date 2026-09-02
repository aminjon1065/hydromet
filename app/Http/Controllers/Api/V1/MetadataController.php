<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alerts\Enums\AlertSeverity;
use App\Domain\Stations\Models\Parameter;
use App\Http\Controllers\Controller;
use App\Support\Locale\SupportedLocale;
use Illuminate\Http\JsonResponse;

class MetadataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $parameters = Parameter::query()
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(static fn (Parameter $parameter): array => [
                'code' => $parameter->code,
                'name' => $parameter->localizedName(),
                'kind' => $parameter->kind->value,
                'unit' => $parameter->canonical_unit,
                'precision' => $parameter->precision,
                'default_averaging_period' => $parameter->default_averaging_period,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'languages' => array_map(
                    static fn (SupportedLocale $locale): array => [
                        'code' => $locale->value,
                        'bcp47' => $locale->bcp47(),
                        'name' => $locale->nativeName(),
                    ],
                    SupportedLocale::cases(),
                ),
                'timezone' => 'Asia/Dushanbe',
                'parameters' => $parameters,
                // No scheme is published before Hydromet approves breakpoints.
                'aqi_available' => false,
                'aqi_schemes' => [],
                // Capability flags, so a client can hide a section instead of
                // rendering an empty one it cannot explain.
                'alerts_available' => true,
                'alert_severity_order' => AlertSeverity::descendingRankValues(),
                // The portal ranks severity but publishes no colour scale of
                // its own: Hydromet has not approved one
                // (docs/08-hydromet-input-checklist.md, section 3).
                'alert_severity_palette_approved' => false,
            ],
            'meta' => [
                'generated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=300',
            'Vary' => 'Accept-Language',
        ]);
    }
}

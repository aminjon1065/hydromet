<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use App\Domain\Stations\Queries\PublicStationOverview;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;

class StationShowController extends Controller
{
    public function __invoke(Station $station, PublicStationOverview $overview): JsonResponse
    {
        abort_if($station->status === StationStatus::Decommissioned, 404);

        $station->load([
            'parameters' => fn (BelongsToMany $query) => $query
                ->where('parameters.active', true)
                ->orderBy('parameters.code'),
        ]);
        $source = IntegrationSource::query()->where('code', $station->source)->first();
        $lastRun = $source?->synchronizationRuns()
            ->where('kind', SynchronizationKind::Measurements)
            ->whereIn('status', [SynchronizationStatus::Succeeded, SynchronizationStatus::Partial])
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();
        $snapshot = $overview->getByIds([$station->id]);
        $latest = $snapshot[0]['measurements'] ?? [];
        $measurements = [];

        foreach ($latest as $measurement) {
            $measurements[$measurement['parameter']] = [
                'value' => $measurement['value'],
                'unit' => $measurement['unit'],
                'quality' => $measurement['quality'],
                'observed_at' => $measurement['observedAt'],
            ];
        }

        return response()->json([
            'data' => [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->localizedName(),
                'latitude' => (float) $station->latitude,
                'longitude' => (float) $station->longitude,
                'elevation_m' => $station->elevation_m === null ? null : (float) $station->elevation_m,
                'region_code' => $station->region_code,
                'district_code' => $station->district_code,
                'status' => $station->status->value,
                'station_type' => $station->station_type->value,
                'parameters' => $station->parameters
                    ->map(static fn (Parameter $parameter): array => [
                        'code' => $parameter->code,
                        'name' => $parameter->localizedName(),
                        'unit' => $parameter->canonical_unit,
                        'precision' => $parameter->precision,
                    ])
                    ->values()
                    ->all(),
                'measurements' => $measurements === [] ? (object) [] : $measurements,
                'source' => [
                    'code' => $station->source,
                    'is_mock' => $station->source === 'fixture',
                    'last_success_at' => $lastRun?->finished_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    'stale_after_seconds' => null,
                    'is_stale' => null,
                ],
                'aqi' => null,
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Accept-Language',
        ]);
    }
}

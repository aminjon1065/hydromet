<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\Enums\SynchronizationKind;
use App\Domain\Integrations\Enums\SynchronizationStatus;
use App\Domain\Integrations\Models\IntegrationSource;
use App\Domain\Measurements\Enums\PublicSeriesPeriod;
use App\Domain\Measurements\Queries\PublicStationParameterSelection;
use App\Domain\Measurements\Queries\PublicStationSeries;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StationController extends Controller
{
    public function __invoke(
        Request $request,
        Station $station,
        PublicStationParameterSelection $parameterSelection,
        PublicStationSeries $seriesQuery,
    ): Response {
        abort_if($station->status === StationStatus::Decommissioned, 404);

        $suppliedPeriod = $request->query('period', PublicSeriesPeriod::Hours24->value);
        $period = is_string($suppliedPeriod) ? PublicSeriesPeriod::tryFrom($suppliedPeriod) : null;
        abort_if($period === null, 404);

        $station->load([
            'parameters' => fn (BelongsToMany $query) => $query
                ->where('parameters.active', true)
                ->orderBy('parameters.code'),
        ]);

        try {
            $selectedParameters = $parameterSelection->resolve(
                $station,
                $request->query('parameters'),
            );
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        $source = IntegrationSource::query()->where('code', $station->source)->first();
        $lastRun = $source?->synchronizationRuns()
            ->where('kind', SynchronizationKind::Measurements)
            ->whereIn('status', [SynchronizationStatus::Succeeded, SynchronizationStatus::Partial])
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();

        return Inertia::render('stations/show', [
            'station' => [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->localizedName(),
                'latitude' => (float) $station->latitude,
                'longitude' => (float) $station->longitude,
                'elevationM' => $station->elevation_m === null ? null : (float) $station->elevation_m,
                'regionCode' => $station->region_code,
                'districtCode' => $station->district_code,
                'status' => $station->status->value,
                'stationType' => $station->station_type->value,
                'source' => $station->source,
                'isMock' => $station->source === 'fixture',
                'lastSynchronizationAt' => $lastRun?->finished_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'parameters' => $station->parameters
                    ->map(static fn (Parameter $parameter): array => [
                        'code' => $parameter->code,
                        'name' => $parameter->localizedName(),
                        'unit' => $parameter->canonical_unit,
                        'precision' => $parameter->precision,
                    ])
                    ->values()
                    ->all(),
            ],
            'range' => $seriesQuery->get($station, $period, parameters: $selectedParameters),
            'periods' => PublicSeriesPeriod::values(),
            'selectedParameters' => $selectedParameters,
        ]);
    }
}

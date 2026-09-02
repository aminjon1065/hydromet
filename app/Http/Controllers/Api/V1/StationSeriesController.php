<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Measurements\Enums\PublicSeriesAggregation;
use App\Domain\Measurements\Queries\PublicSeriesSelectionFactory;
use App\Domain\Measurements\Queries\PublicStationSeries;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Station;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationSeriesController extends Controller
{
    public function __invoke(
        Request $request,
        Station $station,
        PublicSeriesSelectionFactory $selectionFactory,
        PublicStationSeries $seriesQuery,
    ): JsonResponse {
        abort_if($station->status === StationStatus::Decommissioned, 404);

        $selection = $selectionFactory->fromRequest($request, $station);
        $range = $seriesQuery->getRange(
            $station,
            $selection->from,
            $selection->to,
            $selection->aggregation,
            $selection->parameters,
            $selection->timezone,
            $selection->qualities,
        );

        $series = array_map(static fn (array $item): array => [
            'parameter' => $item['parameter'],
            'unit' => $item['unit'],
            'precision' => $item['precision'],
            'points' => array_map(static fn (array $point): array => [
                'time' => $point['time'],
                'value' => $point['value'],
                'quality' => $point['quality'],
                'corrected' => $point['corrected'],
                'sample_count' => $point['sampleCount'],
            ], $item['points']),
        ], $range['series']);

        $maxAge = match ($selection->aggregation) {
            PublicSeriesAggregation::Raw => 300,
            PublicSeriesAggregation::Hour => 900,
            PublicSeriesAggregation::Day, PublicSeriesAggregation::Month => 3600,
        };

        return response()->json([
            'station' => [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->localizedName(),
            ],
            'range' => [
                'from' => $range['from'],
                'to' => $range['to'],
                'aggregation' => $range['aggregation'],
                'timezone' => $selection->timezone->value,
            ],
            'series' => $series,
        ])->withHeaders([
            'Cache-Control' => "public, max-age={$maxAge}",
            'Vary' => 'Accept-Language',
        ]);
    }
}

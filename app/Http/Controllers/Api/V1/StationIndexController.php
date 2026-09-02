<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Station;
use App\Domain\Stations\Queries\PublicStationOverview;
use App\Http\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Support\Canonical\CanonicalReader;
use App\Support\Canonical\InvalidCanonicalRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StationIndexController extends Controller
{
    public function __invoke(Request $request, PublicStationOverview $overview): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(StationStatus::class)->only([
                StationStatus::Active,
                StationStatus::Maintenance,
                StationStatus::Offline,
            ])],
            'parameter' => ['nullable', 'string', 'max:64'],
            'updated_after' => ['nullable', 'string', 'max:40'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        $query = Station::query()
            ->where('status', '!=', StationStatus::Decommissioned);

        if (isset($validated['bbox'])) {
            [$west, $south, $east, $north] = $this->bbox($validated['bbox']);
            $query->whereBetween('longitude', [$west, $east])
                ->whereBetween('latitude', [$south, $north]);
        }

        if (isset($validated['region'])) {
            $query->where('region_code', $validated['region']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['parameter'])) {
            $parameter = $validated['parameter'];
            $query->whereHas('parameters', static fn (Builder $builder) => $builder
                ->where('parameters.active', true)
                ->where('parameters.code', $parameter));
        }

        if (isset($validated['updated_after'])) {
            $updatedAfter = $this->dateTime($validated['updated_after'], 'updated_after');
            // A station is "changed" when its own record changed or when one of
            // its measurements was revised. The correlated existence check stops
            // at the first matching measurement and rides
            // `measurements_station_updated_at_index`, where materialising every
            // changed station id would scan the whole measurement table.
            $query->where(static function (Builder $builder) use ($updatedAfter): void {
                $builder->where('stations.updated_at', '>', $updatedAfter)
                    ->orWhereExists(static fn (QueryBuilder $measurements) => $measurements
                        ->from('measurements')
                        ->whereColumn('measurements.station_id', 'stations.id')
                        ->where('measurements.updated_at', '>', $updatedAfter));
            });
        }

        $page = $query
            ->orderBy('code')
            ->orderBy('id')
            ->cursorPaginate(100, ['stations.*'], 'cursor');
        $stationIds = array_values($page->getCollection()
            ->map(static fn (Station $station): int => $station->id)
            ->all());

        $data = array_map(static function (array $station): array {
            $measurements = [];

            foreach ($station['measurements'] as $measurement) {
                $measurements[$measurement['parameter']] = [
                    'value' => $measurement['value'],
                    'unit' => $measurement['unit'],
                    'quality' => $measurement['quality'],
                    'observed_at' => $measurement['observedAt'],
                ];
            }

            return [
                'id' => $station['id'],
                'code' => $station['code'],
                'name' => $station['name'],
                'latitude' => $station['latitude'],
                'longitude' => $station['longitude'],
                'status' => $station['status'],
                'source' => $station['source'],
                'is_mock' => $station['isMock'],
                'observed_at' => $station['observedAt'],
                // Hydromet has not supplied the stale threshold yet.
                'is_stale' => null,
                // AQI stays absent until the calculation scheme is approved.
                'aqi' => null,
                'measurements' => $measurements === [] ? (object) [] : $measurements,
            ];
        }, $overview->getByIds($stationIds));

        return response()->json([
            'data' => $data,
            'meta' => [
                'generated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=60',
            'Vary' => 'Accept-Language',
        ]);
    }

    /**
     * @return array{float, float, float, float}
     */
    private function bbox(string $value): array
    {
        $parts = explode(',', $value);

        if (count($parts) !== 4) {
            throw $this->invalidBbox();
        }

        $numbers = array_map(static function (string $part): float {
            $trimmed = trim($part);

            if ($trimmed === '' || ! is_numeric($trimmed)) {
                throw new ApiProblem(422, 'invalid_bbox', 'The bbox must contain four numeric coordinates.');
            }

            return (float) $trimmed;
        }, $parts);
        [$west, $south, $east, $north] = $numbers;

        if ($west < -180 || $east > 180 || $south < -90 || $north > 90 || $west > $east || $south > $north) {
            throw $this->invalidBbox();
        }

        return [$west, $south, $east, $north];
    }

    private function invalidBbox(): ApiProblem
    {
        return new ApiProblem(
            422,
            'invalid_bbox',
            'The bbox must be west,south,east,north with valid ordered coordinates.',
            ['field' => 'bbox'],
        );
    }

    private function dateTime(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::instance((new CanonicalReader([$field => $value]))->dateTime($field));
        } catch (InvalidCanonicalRow) {
            throw new ApiProblem(
                422,
                'invalid_datetime',
                "The {$field} query parameter must be an ISO 8601 timestamp with an explicit timezone.",
                ['field' => $field],
            );
        }
    }
}

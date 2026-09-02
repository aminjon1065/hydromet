<?php

namespace App\Domain\Stations\Queries;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Station;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the compact national station snapshot used by the public home map.
 *
 * Invalid measurements are never public. Missing readings remain present with
 * a null value, and suspect readings retain their quality label. Until
 * Hydromet approves a multi-sensor display rule, the newest observation per
 * station and parameter is selected deterministically.
 */
final class PublicStationOverview
{
    /**
     * @return list<array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     status: string,
     *     source: string,
     *     isMock: bool,
     *     observedAt: string|null,
     *     measurements: list<array{
     *         parameter: string,
     *         value: float|null,
     *         unit: string,
     *         precision: int,
     *         quality: string,
     *         observedAt: string
     *     }>
     * }>
     */
    public function get(): array
    {
        $stations = Station::query()
            ->where('status', '!=', StationStatus::Decommissioned)
            ->orderBy('code')
            ->get();

        return $this->build($stations);
    }

    /**
     * Build the same snapshot for a cursor-paginated station subset.
     *
     * @param  list<int>  $stationIds
     * @return list<array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     status: string,
     *     source: string,
     *     isMock: bool,
     *     observedAt: string|null,
     *     measurements: list<array{
     *         parameter: string,
     *         value: float|null,
     *         unit: string,
     *         precision: int,
     *         quality: string,
     *         observedAt: string
     *     }>
     * }>
     */
    public function getByIds(array $stationIds): array
    {
        if ($stationIds === []) {
            return [];
        }

        $stations = Station::query()
            ->whereIn('id', $stationIds)
            ->where('status', '!=', StationStatus::Decommissioned)
            ->orderBy('code')
            ->get();

        return $this->build($stations);
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @return list<array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     status: string,
     *     source: string,
     *     isMock: bool,
     *     observedAt: string|null,
     *     measurements: list<array{
     *         parameter: string,
     *         value: float|null,
     *         unit: string,
     *         precision: int,
     *         quality: string,
     *         observedAt: string
     *     }>
     * }>
     */
    private function build(Collection $stations): array
    {

        $stationIds = array_values($stations
            ->map(static fn (Station $station): int => $station->id)
            ->all());
        $latest = $this->latestMeasurements($stationIds);

        $overview = $stations
            ->map(function (Station $station) use ($latest): array {
                $measurements = $latest[$station->id] ?? [];
                $observedAt = $measurements === []
                    ? null
                    : max(array_column($measurements, 'observedAt'));

                return [
                    'id' => $station->id,
                    'code' => $station->code,
                    'name' => $station->localizedName(),
                    'latitude' => (float) $station->latitude,
                    'longitude' => (float) $station->longitude,
                    'status' => $station->status->value,
                    'source' => $station->source,
                    'isMock' => $station->source === 'fixture',
                    'observedAt' => $observedAt,
                    'measurements' => $measurements,
                ];
            })
            ->values()
            ->all();

        return array_values($overview);
    }

    /**
     * @param  list<int>  $stationIds
     * @return array<int, list<array{
     *     parameter: string,
     *     value: float|null,
     *     unit: string,
     *     precision: int,
     *     quality: string,
     *     observedAt: string
     * }>>
     */
    private function latestMeasurements(array $stationIds): array
    {
        if ($stationIds === []) {
            return [];
        }

        $ranked = DB::table('measurements')
            ->join('parameters', 'parameters.id', '=', 'measurements.parameter_id')
            ->whereIn('measurements.station_id', $stationIds)
            ->where('measurements.quality', '!=', MeasurementQuality::Invalid->value)
            ->where('parameters.active', true)
            ->select([
                'measurements.id',
                'measurements.station_id',
                'measurements.value',
                'measurements.unit',
                'measurements.quality',
                'measurements.observed_at',
                'parameters.code as parameter_code',
                'parameters.precision as parameter_precision',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY measurements.station_id, measurements.parameter_id '
                .'ORDER BY measurements.observed_at DESC, measurements.id DESC) AS observation_rank'
            );

        $grouped = [];

        foreach (DB::query()->fromSub($ranked, 'ranked_measurements')
            ->where('observation_rank', 1)
            ->orderBy('station_id')
            ->orderBy('parameter_code')
            ->get() as $row) {
            $stationId = (int) data_get($row, 'station_id');
            $value = data_get($row, 'value');

            $grouped[$stationId][] = [
                'parameter' => (string) data_get($row, 'parameter_code'),
                'value' => $value === null ? null : (float) $value,
                'unit' => (string) data_get($row, 'unit'),
                'precision' => (int) data_get($row, 'parameter_precision'),
                'quality' => (string) data_get($row, 'quality'),
                'observedAt' => Carbon::parse((string) data_get($row, 'observed_at'))
                    ->utc()
                    ->format('Y-m-d\TH:i:s.u\Z'),
            ];
        }

        return $grouped;
    }
}

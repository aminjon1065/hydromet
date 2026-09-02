<?php

namespace App\Domain\Measurements\Queries;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Enums\PublicSeriesAggregation;
use App\Domain\Measurements\Enums\PublicSeriesPeriod;
use App\Domain\Measurements\Enums\PublicSeriesTimezone;
use App\Domain\Stations\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Produces public chart points while excluding invalid readings by default.
 *
 * The observation window is half-open `[from, to)`, as documented in
 * docs/05-api-contract.md section 1, so adjacent requests tile a period without
 * repeating the boundary observation.
 */
final class PublicStationSeries
{
    /**
     * @param  list<string>|null  $parameters
     * @return array{
     *     from: string,
     *     to: string,
     *     period: string,
     *     aggregation: string,
     *     series: list<array{
     *         parameter: string,
     *         unit: string,
     *         precision: int,
     *         points: list<array{
     *             time: string,
     *             value: float|null,
     *             quality: string,
     *             corrected: bool,
     *             sampleCount: int
     *         }>
     *     }>
     * }
     */
    public function get(
        Station $station,
        PublicSeriesPeriod $period,
        ?CarbonImmutable $to = null,
        ?array $parameters = null,
    ): array {
        $to ??= CarbonImmutable::now('UTC');
        $to = $to->utc();
        $from = $period->startsAt($to);

        $range = $this->getRange(
            $station,
            $from,
            $to,
            $period->aggregation(),
            $parameters,
            PublicSeriesTimezone::Dushanbe,
        );

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'period' => $period->value,
            'aggregation' => $range['aggregation'],
            'series' => $range['series'],
        ];
    }

    /**
     * @param  list<string>|null  $parameters
     * @param  list<string>|null  $qualities
     * @return array{
     *     from: string,
     *     to: string,
     *     aggregation: string,
     *     series: list<array{
     *         parameter: string,
     *         unit: string,
     *         precision: int,
     *         points: list<array{
     *             time: string,
     *             value: float|null,
     *             quality: string,
     *             corrected: bool,
     *             sampleCount: int
     *         }>
     *     }>
     * }
     */
    public function getRange(
        Station $station,
        CarbonImmutable $from,
        CarbonImmutable $to,
        PublicSeriesAggregation $aggregation,
        ?array $parameters = null,
        PublicSeriesTimezone $timezone = PublicSeriesTimezone::Dushanbe,
        ?array $qualities = null,
    ): array {
        $from = $from->utc();
        $to = $to->utc();
        $series = $aggregation === PublicSeriesAggregation::Raw
            ? $this->raw($station, $from, $to, $parameters, $qualities)
            : $this->aggregated(
                $station,
                $from,
                $to,
                $aggregation,
                $parameters,
                $timezone,
                $qualities,
            );

        return [
            'from' => $this->timestamp($from),
            'to' => $this->timestamp($to),
            'aggregation' => $aggregation->value,
            'series' => array_values($series),
        ];
    }

    /**
     * @param  list<string>|null  $parameters
     * @param  list<string>|null  $qualities
     * @return array<string, array{
     *     parameter: string,
     *     unit: string,
     *     precision: int,
     *     points: list<array{time: string, value: float|null, quality: string, corrected: bool, sampleCount: int}>
     * }>
     */
    private function raw(
        Station $station,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?array $parameters,
        ?array $qualities,
    ): array {
        $query = DB::table('measurements')
            ->join('parameters', 'parameters.id', '=', 'measurements.parameter_id')
            ->where('measurements.station_id', $station->id)
            ->where('parameters.active', true)
            ->where('measurements.quality', '!=', MeasurementQuality::Invalid->value)
            ->where('measurements.observed_at', '>=', $from)
            ->where('measurements.observed_at', '<', $to);

        if ($parameters !== null) {
            $query->whereIn('parameters.code', $parameters);
        }

        if ($qualities !== null) {
            $query->whereIn('measurements.quality', $qualities);
        }

        $rows = $query
            ->orderBy('parameters.code')
            ->orderBy('measurements.observed_at')
            ->orderBy('measurements.id')
            ->get([
                'parameters.code as parameter_code',
                'parameters.precision as parameter_precision',
                'measurements.unit',
                'measurements.observed_at',
                'measurements.value',
                'measurements.quality',
                'measurements.revision',
            ]);

        $series = [];

        foreach ($rows as $row) {
            $parameter = (string) data_get($row, 'parameter_code');
            $value = data_get($row, 'value');

            $series[$parameter] ??= $this->emptySeries(
                $parameter,
                (string) data_get($row, 'unit'),
                (int) data_get($row, 'parameter_precision'),
            );
            $series[$parameter]['points'][] = [
                'time' => $this->timestamp(Carbon::parse((string) data_get($row, 'observed_at'))),
                'value' => $value === null ? null : (float) $value,
                'quality' => (string) data_get($row, 'quality'),
                'corrected' => (int) data_get($row, 'revision') > 1,
                'sampleCount' => 1,
            ];
        }

        return $series;
    }

    /**
     * @param  list<string>|null  $parameters
     * @param  list<string>|null  $qualities
     * @return array<string, array{
     *     parameter: string,
     *     unit: string,
     *     precision: int,
     *     points: list<array{time: string, value: float|null, quality: string, corrected: bool, sampleCount: int}>
     * }>
     */
    private function aggregated(
        Station $station,
        CarbonImmutable $from,
        CarbonImmutable $to,
        PublicSeriesAggregation $aggregation,
        ?array $parameters,
        PublicSeriesTimezone $timezone,
        ?array $qualities,
    ): array {
        $bucket = $this->bucketExpression($aggregation, $timezone);

        $query = DB::table('measurements')
            ->join('parameters', 'parameters.id', '=', 'measurements.parameter_id')
            ->where('measurements.station_id', $station->id)
            ->where('parameters.active', true)
            ->where('measurements.quality', '!=', MeasurementQuality::Invalid->value)
            ->whereNotNull('measurements.value')
            ->where('measurements.observed_at', '>=', $from)
            ->where('measurements.observed_at', '<', $to);

        if ($parameters !== null) {
            $query->whereIn('parameters.code', $parameters);
        }

        if ($qualities !== null) {
            $query->whereIn('measurements.quality', $qualities);
        }

        $rows = $query
            ->select([
                'parameters.code as parameter_code',
                'parameters.precision as parameter_precision',
                'measurements.unit',
            ])
            ->selectRaw("{$bucket} AS time_bucket")
            ->selectRaw('AVG(measurements.value) AS aggregate_value')
            ->selectRaw('COUNT(measurements.value) AS sample_count')
            ->selectRaw("MAX(CASE WHEN measurements.quality = 'suspect' THEN 1 ELSE 0 END) AS has_suspect")
            ->selectRaw("MAX(CASE WHEN measurements.quality = 'corrected' THEN 1 ELSE 0 END) AS has_corrected")
            ->selectRaw('MAX(CASE WHEN measurements.revision > 1 THEN 1 ELSE 0 END) AS was_corrected')
            ->groupBy('parameters.code', 'parameters.precision', 'measurements.unit')
            ->groupByRaw($bucket)
            ->orderBy('parameters.code')
            ->orderBy('time_bucket')
            ->get();

        $series = [];

        foreach ($rows as $row) {
            $parameter = (string) data_get($row, 'parameter_code');
            $quality = (int) data_get($row, 'has_suspect') === 1
                ? MeasurementQuality::Suspect->value
                : ((int) data_get($row, 'has_corrected') === 1
                    ? MeasurementQuality::Corrected->value
                    : MeasurementQuality::Valid->value);

            $series[$parameter] ??= $this->emptySeries(
                $parameter,
                (string) data_get($row, 'unit'),
                (int) data_get($row, 'parameter_precision'),
            );
            $series[$parameter]['points'][] = [
                'time' => $this->timestamp(Carbon::parse((string) data_get($row, 'time_bucket'), 'UTC')),
                'value' => (float) data_get($row, 'aggregate_value'),
                'quality' => $quality,
                'corrected' => (int) data_get($row, 'was_corrected') === 1,
                'sampleCount' => (int) data_get($row, 'sample_count'),
            ];
        }

        return $series;
    }

    /**
     * Every returned expression is application-owned SQL, never request data.
     *
     * @return literal-string
     */
    private function bucketExpression(
        PublicSeriesAggregation $aggregation,
        PublicSeriesTimezone $timezone,
    ): string {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return match ([$aggregation, $timezone]) {
                [PublicSeriesAggregation::Hour, PublicSeriesTimezone::Utc] => "date_trunc('hour', measurements.observed_at)",
                [PublicSeriesAggregation::Day, PublicSeriesTimezone::Utc] => "date_trunc('day', measurements.observed_at)",
                [PublicSeriesAggregation::Month, PublicSeriesTimezone::Utc] => "date_trunc('month', measurements.observed_at)",
                [PublicSeriesAggregation::Hour, PublicSeriesTimezone::Dushanbe] => "date_trunc('hour', measurements.observed_at AT TIME ZONE 'Asia/Dushanbe') AT TIME ZONE 'Asia/Dushanbe'",
                [PublicSeriesAggregation::Day, PublicSeriesTimezone::Dushanbe] => "date_trunc('day', measurements.observed_at AT TIME ZONE 'Asia/Dushanbe') AT TIME ZONE 'Asia/Dushanbe'",
                [PublicSeriesAggregation::Month, PublicSeriesTimezone::Dushanbe] => "date_trunc('month', measurements.observed_at AT TIME ZONE 'Asia/Dushanbe') AT TIME ZONE 'Asia/Dushanbe'",
                default => throw new \LogicException('Raw observations do not use an SQL bucket.'),
            };
        }

        return match ([$aggregation, $timezone]) {
            [PublicSeriesAggregation::Hour, PublicSeriesTimezone::Utc] => "strftime('%Y-%m-%d %H:00:00', measurements.observed_at)",
            [PublicSeriesAggregation::Day, PublicSeriesTimezone::Utc] => "strftime('%Y-%m-%d 00:00:00', measurements.observed_at)",
            [PublicSeriesAggregation::Month, PublicSeriesTimezone::Utc] => "strftime('%Y-%m-01 00:00:00', measurements.observed_at)",
            [PublicSeriesAggregation::Hour, PublicSeriesTimezone::Dushanbe] => "strftime('%Y-%m-%d %H:00:00', measurements.observed_at, '+5 hours', '-5 hours')",
            [PublicSeriesAggregation::Day, PublicSeriesTimezone::Dushanbe] => "datetime(strftime('%Y-%m-%d 00:00:00', measurements.observed_at, '+5 hours'), '-5 hours')",
            [PublicSeriesAggregation::Month, PublicSeriesTimezone::Dushanbe] => "datetime(strftime('%Y-%m-01 00:00:00', measurements.observed_at, '+5 hours'), '-5 hours')",
            default => throw new \LogicException('Raw observations do not use an SQL bucket.'),
        };
    }

    /**
     * @return array{
     *     parameter: string,
     *     unit: string,
     *     precision: int,
     *     points: list<array{time: string, value: float|null, quality: string, corrected: bool, sampleCount: int}>
     * }
     */
    private function emptySeries(string $parameter, string $unit, int $precision): array
    {
        return [
            'parameter' => $parameter,
            'unit' => $unit,
            'precision' => $precision,
            'points' => [],
        ];
    }

    private function timestamp(\DateTimeInterface $moment): string
    {
        return Carbon::instance($moment)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}

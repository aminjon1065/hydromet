<?php

namespace App\Domain\Measurements\Queries;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Stations\Models\Station;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cursor-backed public CSV rows shared by the browser and `/api/v1` exports.
 *
 * The observation window is half-open `[from, to)`, as documented in
 * docs/05-api-contract.md section 1, so stitched exports never duplicate the
 * observation that sits on the boundary.
 */
final class PublicStationMeasurementRows
{
    /** @var list<string> */
    public const HEADER = [
        'station_code',
        'parameter',
        'observed_at_utc',
        'value',
        'unit',
        'quality',
        'revision',
        'corrected',
    ];

    /**
     * @param  list<string>  $parameters
     * @param  list<string>|null  $qualities
     * @return Generator<int, list<string>, void, void>
     */
    public function get(
        Station $station,
        array $parameters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?array $qualities = null,
    ): Generator {
        $query = DB::table('measurements')
            ->join('parameters', 'parameters.id', '=', 'measurements.parameter_id')
            ->where('measurements.station_id', $station->id)
            ->where('parameters.active', true)
            ->whereIn('parameters.code', $parameters)
            ->where('measurements.quality', '!=', MeasurementQuality::Invalid->value)
            ->where('measurements.observed_at', '>=', $from)
            ->where('measurements.observed_at', '<', $to);

        if ($qualities !== null) {
            $query->whereIn('measurements.quality', $qualities);
        }

        $rows = $query
            ->orderBy('measurements.observed_at')
            ->orderBy('parameters.code')
            ->orderBy('measurements.id')
            ->select([
                'parameters.code as parameter_code',
                'measurements.observed_at',
                'measurements.value',
                'measurements.unit',
                'measurements.quality',
                'measurements.revision',
            ])
            ->cursor();

        foreach ($rows as $row) {
            $value = data_get($row, 'value');

            yield [
                $this->safeText($station->code),
                $this->safeText((string) data_get($row, 'parameter_code')),
                Carbon::parse((string) data_get($row, 'observed_at'))->utc()->format('Y-m-d\TH:i:s.u\Z'),
                $value === null ? '' : (string) $value,
                $this->safeText((string) data_get($row, 'unit')),
                (string) data_get($row, 'quality'),
                (string) data_get($row, 'revision'),
                (int) data_get($row, 'revision') > 1 ? 'true' : 'false',
            ];
        }
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}

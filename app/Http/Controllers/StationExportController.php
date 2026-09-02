<?php

namespace App\Http\Controllers;

use App\Domain\Measurements\Enums\PublicSeriesPeriod;
use App\Domain\Measurements\Queries\PublicStationMeasurementRows;
use App\Domain\Measurements\Queries\PublicStationParameterSelection;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams effective public measurements without loading a year into memory.
 *
 * The delimiter and columns remain provisional until Hydromet supplies the
 * acceptance fixture requested in docs/08-hydromet-input-checklist.md.
 */
class StationExportController extends Controller
{
    public function __invoke(
        Request $request,
        Station $station,
        PublicStationParameterSelection $parameterSelection,
        PublicStationMeasurementRows $measurementRows,
    ): StreamedResponse {
        abort_if($station->status === StationStatus::Decommissioned, 404);

        $suppliedPeriod = $request->query('period', PublicSeriesPeriod::Hours24->value);
        $period = is_string($suppliedPeriod) ? PublicSeriesPeriod::tryFrom($suppliedPeriod) : null;
        abort_if($period === null, 404);

        try {
            $parameters = $parameterSelection->resolve($station, $request->query('parameters'));
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        $to = CarbonImmutable::now('UTC');
        $from = $period->startsAt($to);
        $filenameCode = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $station->code) ?: 'station';

        return response()->streamDownload(
            function () use ($station, $parameters, $from, $to, $measurementRows): void {
                $output = fopen('php://output', 'wb');

                if ($output === false) {
                    return;
                }

                fputcsv($output, PublicStationMeasurementRows::HEADER, ',', '"', '');

                foreach ($measurementRows->get($station, $parameters, $from, $to) as $row) {
                    fputcsv($output, $row, ',', '"', '');
                }

                fclose($output);
            },
            "station-{$filenameCode}-{$period->value}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}

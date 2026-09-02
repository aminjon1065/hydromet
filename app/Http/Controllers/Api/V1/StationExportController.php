<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Measurements\Queries\PublicSeriesSelectionFactory;
use App\Domain\Measurements\Queries\PublicStationMeasurementRows;
use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Models\Station;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StationExportController extends Controller
{
    public function __invoke(
        Request $request,
        Station $station,
        PublicSeriesSelectionFactory $selectionFactory,
        PublicStationMeasurementRows $measurementRows,
    ): StreamedResponse {
        abort_if($station->status === StationStatus::Decommissioned, 404);
        $selection = $selectionFactory->fromRequest($request, $station);
        $filenameCode = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $station->code) ?: 'station';

        return response()->streamDownload(
            function () use ($station, $selection, $measurementRows): void {
                $output = fopen('php://output', 'wb');

                if ($output === false) {
                    return;
                }

                fputcsv($output, PublicStationMeasurementRows::HEADER, ',', '"', '');

                foreach ($measurementRows->get(
                    $station,
                    $selection->parameters,
                    $selection->from,
                    $selection->to,
                    $selection->qualities,
                ) as $row) {
                    fputcsv($output, $row, ',', '"', '');
                }

                fclose($output);
            },
            "station-{$filenameCode}-{$selection->from->format('Ymd')}-{$selection->to->format('Ymd')}.csv",
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300',
            ],
        );
    }
}

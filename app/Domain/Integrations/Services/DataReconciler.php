<?php

namespace App\Domain\Integrations\Services;

use App\Domain\Alerts\Models\AlertMessage;
use App\Domain\Integrations\Data\ReconciliationReport;
use App\Domain\Integrations\Data\ReconciliationSnapshot;
use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Stations\Models\Station;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles the portal's imported copy against source-supplied aggregate
 * totals from docs/06-testing-and-acceptance.md, section 3.
 */
final class DataReconciler
{
    public function reconcile(ReconciliationSnapshot $expected, ?Carbon $moment = null): ReconciliationReport
    {
        return new ReconciliationReport($expected, $this->snapshot($expected->source, $moment));
    }

    /**
     * @param  Carbon|null  $moment  The instant the warning count is taken at.
     *                               Every other total is time-independent; this
     *                               one is not, so a caller that needs a
     *                               repeatable answer supplies it.
     */
    public function snapshot(string $source, ?Carbon $moment = null): ReconciliationSnapshot
    {
        $moment ??= Carbon::now('UTC');
        $measurements = Measurement::query()->where('source', $source);

        return new ReconciliationSnapshot(
            source: $source,
            stationCount: Station::query()->where('source', $source)->count(),
            measurementCount: (clone $measurements)->count(),
            measurementCounts: $this->measurementCounts($source),
            firstObservedAt: $this->timestamp((clone $measurements)->min('observed_at')),
            lastObservedAt: $this->timestamp((clone $measurements)->max('observed_at')),
            missingValueCount: (clone $measurements)->whereNull('value')->count(),
            invalidOrSuspectCount: (clone $measurements)->whereIn('quality', [
                MeasurementQuality::Invalid->value,
                MeasurementQuality::Suspect->value,
            ])->count(),
            revisionCount: MeasurementRevision::query()
                ->whereHas('measurement', fn ($query) => $query->where('source', $source))
                ->count(),
            // Counted through the model scope, so reconciliation, the public
            // list, the API and the panel all answer "in force" the same way.
            activeAlertCount: AlertMessage::query()
                ->where('source', $source)
                ->activeAt($moment)
                ->count(),
        );
    }

    /**
     * @return list<array{station_external_id: string, parameter_code: string, count: int}>
     */
    private function measurementCounts(string $source): array
    {
        $counts = DB::table('measurements')
            ->join('stations', 'stations.id', '=', 'measurements.station_id')
            ->join('parameters', 'parameters.id', '=', 'measurements.parameter_id')
            ->where('measurements.source', $source)
            ->select([
                'stations.external_id as station_external_id',
                'parameters.code as parameter_code',
            ])
            ->selectRaw('COUNT(*) as measurement_count')
            ->groupBy('stations.external_id', 'parameters.code')
            ->orderBy('stations.external_id')
            ->orderBy('parameters.code')
            ->get()
            ->map(static fn (object $row): array => [
                'station_external_id' => (string) data_get($row, 'station_external_id'),
                'parameter_code' => (string) data_get($row, 'parameter_code'),
                'count' => (int) data_get($row, 'measurement_count'),
            ])
            ->all();

        return array_values($counts);
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse((string) $value)
            ->utc()
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}

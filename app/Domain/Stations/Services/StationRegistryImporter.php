<?php

namespace App\Domain\Stations\Services;

use App\Domain\Integrations\Contracts\StationRegistryProvider;
use App\Domain\Stations\Data\ImportResult;
use App\Domain\Stations\Data\ParameterCatalogueBatch;
use App\Domain\Stations\Data\ParameterRecord;
use App\Domain\Stations\Data\RejectedRow;
use App\Domain\Stations\Data\StationRecord;
use App\Domain\Stations\Data\StationRegistryBatch;
use App\Domain\Stations\Data\StationRegistryImportReport;
use App\Domain\Stations\Enums\RejectionReason;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the canonical station registry into the portal's own tables.
 *
 * This service is the only writer of `stations`, `parameters` and
 * `station_parameter` (docs/02-architecture.md, section 4). Adapters hand it
 * canonical records; it decides what is acceptable and what is stored.
 *
 * Guarantees:
 *   - identity is `source` + `external_id`, so a repeated import is a no-op;
 *   - each row is written in its own transaction, so one rejected row never
 *     rolls back the rows around it (docs/02-architecture.md, section 7);
 *   - a station absent from a batch is left untouched. Hydromet has not yet
 *     confirmed whether an export is a full registry or a delta
 *     (docs/08-hydromet-input-checklist.md, section 1), so nothing is deleted;
 *   - rejections carry a stable reason code and safe text only.
 */
final class StationRegistryImporter
{
    /** Public decimal places the portal is prepared to render. */
    private const MAX_PRECISION = 6;

    public function import(StationRegistryProvider $provider): StationRegistryImportReport
    {
        // The catalogue runs first: a station may only reference parameter
        // codes that already exist, and the portal never invents a unit.
        $parameters = $this->importParameterCatalogue($provider->fetchParameterCatalogue());
        $stations = $this->importStationRegistry($provider->fetchStationRegistry());

        return new StationRegistryImportReport($provider->sourceKey(), $parameters, $stations);
    }

    public function importParameterCatalogue(ParameterCatalogueBatch $batch): ImportResult
    {
        $rejections = $batch->rejections;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $seenCodes = [];

        foreach ($batch->records as $record) {
            $reference = $batch->source.':parameter:'.$record->code;
            $failure = $this->validateParameter($record, $seenCodes);

            if ($failure !== null) {
                $rejections[] = RejectedRow::make($reference, $failure[0], $failure[1]);

                continue;
            }

            $seenCodes[$record->code] = true;

            try {
                $outcome = $this->persistParameter($record);
            } catch (QueryException) {
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::PersistenceConflict,
                    'The parameter could not be stored because it conflicts with an existing catalogue row.',
                );

                continue;
            }

            match ($outcome) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };
        }

        return ImportResult::make($batch->received(), $created, $updated, $unchanged, $rejections);
    }

    public function importStationRegistry(StationRegistryBatch $batch): ImportResult
    {
        $rejections = $batch->rejections;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $seenExternalIds = [];
        $seenCodes = [];

        /** @var array<string, int> $catalogue */
        $catalogue = Parameter::query()->pluck('id', 'code')->all();

        foreach ($batch->records as $record) {
            $reference = $record->identity();
            $failure = $this->validateStation($record, $batch->source, $catalogue, $seenExternalIds, $seenCodes);

            if ($failure !== null) {
                $rejections[] = RejectedRow::make($reference, $failure[0], $failure[1]);

                continue;
            }

            $seenExternalIds[$record->externalId] = true;
            $seenCodes[$record->code] = true;

            $parameterIds = array_map(
                static fn (string $code): int => $catalogue[$code],
                $record->parameterCodes,
            );

            try {
                $outcome = $this->persistStation($record, $parameterIds);
            } catch (QueryException) {
                // Reported without driver output: a database message can carry
                // schema details that do not belong in operator-facing text.
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::PersistenceConflict,
                    'The station could not be stored because its code or identifier conflicts with an existing station.',
                );

                continue;
            }

            match ($outcome) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };
        }

        return ImportResult::make($batch->received(), $created, $updated, $unchanged, $rejections);
    }

    /**
     * @param  array<string, true>  $seenCodes
     * @return array{RejectionReason, string}|null
     */
    private function validateParameter(ParameterRecord $record, array $seenCodes): ?array
    {
        if (isset($seenCodes[$record->code])) {
            return [RejectionReason::DuplicateInBatch, 'Another row in the same batch already uses this parameter code.'];
        }

        if ($record->precision < 0 || $record->precision > self::MAX_PRECISION) {
            return [
                RejectionReason::PrecisionOutOfRange,
                'Precision '.$record->precision.' is outside the supported range 0..'.self::MAX_PRECISION.'.',
            ];
        }

        if ($record->plausibleMin !== null && $record->plausibleMax !== null && $record->plausibleMin > $record->plausibleMax) {
            return [RejectionReason::ImplausibleBounds, 'plausible_min is greater than plausible_max.'];
        }

        return null;
    }

    /**
     * @param  array<string, int>  $catalogue
     * @param  array<string, true>  $seenExternalIds
     * @param  array<string, true>  $seenCodes
     * @return array{RejectionReason, string}|null
     */
    private function validateStation(
        StationRecord $record,
        string $batchSource,
        array $catalogue,
        array $seenExternalIds,
        array $seenCodes,
    ): ?array {
        if ($record->source !== $batchSource) {
            return [RejectionReason::MalformedRow, 'The row declares a different source than the batch it arrived in.'];
        }

        if (isset($seenExternalIds[$record->externalId])) {
            return [RejectionReason::DuplicateInBatch, 'Another row in the same batch already uses this external identifier.'];
        }

        if (isset($seenCodes[$record->code])) {
            return [RejectionReason::DuplicateInBatch, 'Another row in the same batch already uses this station code.'];
        }

        if ($record->latitude < -90.0 || $record->latitude > 90.0) {
            return [RejectionReason::LatitudeOutOfRange, 'Latitude '.$this->describeNumber($record->latitude).' is outside -90..90.'];
        }

        if ($record->longitude < -180.0 || $record->longitude > 180.0) {
            return [RejectionReason::LongitudeOutOfRange, 'Longitude '.$this->describeNumber($record->longitude).' is outside -180..180.'];
        }

        if (! in_array($record->timezone, DateTimeZone::listIdentifiers(), true)) {
            return [
                RejectionReason::UnknownTimezone,
                "Timezone '".RejectedRow::sanitize($record->timezone, 40)."' is not a known IANA identifier.",
            ];
        }

        foreach ($record->parameterCodes as $code) {
            if (! array_key_exists($code, $catalogue)) {
                return [
                    RejectionReason::UnknownParameterCode,
                    "Parameter code '".RejectedRow::sanitize($code, 40)."' is not in the catalogue.",
                ];
            }
        }

        return null;
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function persistParameter(ParameterRecord $record): string
    {
        return DB::transaction(function () use ($record): string {
            $parameter = Parameter::query()->where('code', $record->code)->first();

            if ($parameter === null) {
                Parameter::query()->create(['code' => $record->code, ...$record->attributes()]);

                return 'created';
            }

            $parameter->fill($record->attributes());

            if (! $parameter->isDirty()) {
                return 'unchanged';
            }

            $parameter->save();

            return 'updated';
        });
    }

    /**
     * Each station is written on its own so a rejected neighbour cannot roll it
     * back, while the station row and its parameter links stay atomic together.
     *
     * @param  list<int>  $parameterIds
     * @return 'created'|'updated'|'unchanged'
     */
    private function persistStation(StationRecord $record, array $parameterIds): string
    {
        return DB::transaction(function () use ($record, $parameterIds): string {
            $station = Station::query()
                ->where('source', $record->source)
                ->where('external_id', $record->externalId)
                ->first();

            if ($station === null) {
                $station = Station::query()->create([
                    'source' => $record->source,
                    'external_id' => $record->externalId,
                    ...$record->attributes(),
                ]);

                $station->parameters()->sync($parameterIds);

                return 'created';
            }

            $station->fill($record->attributes());
            $changed = $station->isDirty();

            if ($changed) {
                $station->save();
            }

            // sync() keeps the pair unique and reconciles the station's own
            // list. It never removes a station, only a link the provider no
            // longer reports for that station.
            $sync = $station->parameters()->sync($parameterIds);
            $linksChanged = $sync['attached'] !== [] || $sync['detached'] !== [] || $sync['updated'] !== [];

            return $changed || $linksChanged ? 'updated' : 'unchanged';
        });
    }

    /**
     * Render a coordinate for an operator message without exponent notation.
     */
    private function describeNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}

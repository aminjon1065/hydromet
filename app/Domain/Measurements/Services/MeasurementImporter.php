<?php

namespace App\Domain\Measurements\Services;

use App\Domain\Integrations\Contracts\MeasurementProvider;
use App\Domain\Integrations\Data\SynchronizationWindow;
use App\Domain\Measurements\Data\MeasurementBatch;
use App\Domain\Measurements\Data\MeasurementImportResult;
use App\Domain\Measurements\Data\MeasurementRecord;
use App\Domain\Measurements\Enums\RevisionOrigin;
use App\Domain\Measurements\Models\Measurement;
use App\Domain\Measurements\Models\MeasurementRevision;
use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use App\Support\Canonical\RejectedRow;
use App\Support\Canonical\RejectionReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes canonical measurements into the portal's own tables.
 *
 * This service is the only writer of `measurements` and `measurement_revisions`
 * (docs/02-architecture.md, section 4). Adapters hand it canonical records; it
 * decides what is acceptable, what is stored and what is recorded as history.
 *
 * Identity is the natural key `source + station + parameter + observed_at +
 * sensor_no`. A provider identifier, when supplied, is stored and kept unique
 * within its source, but it is not the match key: a provider may reissue one,
 * while the natural key describes the observation itself.
 *
 * Revision rules, docs/03-data-contracts.md section 5.3:
 *   - first sighting creates the row; no history entry is written, because
 *     nothing changed yet;
 *   - a newer revision moves `value` / `quality` and, when either actually
 *     changed, records the before and after in `measurement_revisions`;
 *   - `original_value` / `original_quality` are written once and never again;
 *   - an older revision is stale and is rejected; the stored row stays
 *     effective;
 *   - the stored revision restated with a different value or quality is a
 *     conflict and is rejected, because the portal cannot tell which reading
 *     the provider means;
 *   - the stored revision restated identically is a no-op.
 *
 * Each row is written in its own transaction, so one rejected row never rolls
 * back the rows around it (docs/02-architecture.md, section 7).
 */
final class MeasurementImporter
{
    public function import(
        MeasurementProvider $provider,
        ?SynchronizationWindow $window = null,
    ): MeasurementImportResult {
        return $this->importBatch($provider->fetchMeasurements($window));
    }

    public function importBatch(MeasurementBatch $batch): MeasurementImportResult
    {
        $rejections = $batch->rejections;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $revisionsCreated = 0;

        $stations = $this->stationsFor($batch);
        $parameters = $this->parametersFor($batch);

        $seenNaturalKeys = [];
        $seenProviderIds = [];

        foreach ($batch->records as $record) {
            $reference = $record->identity();

            $failure = $this->validateShape($record, $batch->source);

            if ($failure !== null) {
                $rejections[] = RejectedRow::make($reference, $failure[0], $failure[1]);

                continue;
            }

            $station = $stations[$record->stationExternalId] ?? null;

            if ($station === null) {
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::UnknownStation,
                    "No station is registered for source '".RejectedRow::sanitize($batch->source, 32)
                        ."' with identifier '".RejectedRow::sanitize($record->stationExternalId, 40)."'.",
                );

                continue;
            }

            $parameter = $parameters[$record->parameterCode] ?? null;

            if ($parameter === null) {
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::UnknownParameterCode,
                    "Parameter code '".RejectedRow::sanitize($record->parameterCode, 40)."' is not in the catalogue.",
                );

                continue;
            }

            // No unit mapping table exists yet (docs/03-data-contracts.md,
            // section 8.1), so a source unit that is not the canonical unit is
            // refused rather than converted by guesswork.
            if ($record->unit !== $parameter->canonical_unit) {
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::UnitMismatch,
                    "Unit '".RejectedRow::sanitize($record->unit, 32)."' is not the canonical unit '"
                        .RejectedRow::sanitize($parameter->canonical_unit, 32)."' for parameter '"
                        .RejectedRow::sanitize($parameter->code, 32)."'.",
                );

                continue;
            }

            $naturalKey = $record->naturalKey($station->id, $parameter->id);

            if (isset($seenNaturalKeys[$naturalKey])) {
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::DuplicateInBatch,
                    'Another row in the same batch already describes this observation.',
                );

                continue;
            }

            if ($record->sourceMeasurementId !== null && isset($seenProviderIds[$record->sourceMeasurementId])) {
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::DuplicateInBatch,
                    'Another row in the same batch already uses this provider measurement identifier.',
                );

                continue;
            }

            try {
                $outcome = $this->persist($record, $station->id, $parameter->id);
            } catch (QueryException) {
                // Reported without driver output: a database message can carry
                // schema details that do not belong in operator-facing text.
                $rejections[] = RejectedRow::make(
                    $reference,
                    RejectionReason::PersistenceConflict,
                    'The measurement could not be stored because it conflicts with an existing observation.',
                );

                continue;
            }

            if ($outcome instanceof RejectedRow) {
                $rejections[] = $outcome;

                continue;
            }

            $seenNaturalKeys[$naturalKey] = true;

            if ($record->sourceMeasurementId !== null) {
                $seenProviderIds[$record->sourceMeasurementId] = true;
            }

            match ($outcome['state']) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };

            $revisionsCreated += $outcome['revision_written'] ? 1 : 0;
        }

        return MeasurementImportResult::make(
            $batch->received(),
            $created,
            $updated,
            $unchanged,
            $revisionsCreated,
            $rejections,
        );
    }

    /**
     * Rules that need neither the registry nor the stored row.
     *
     * @return array{RejectionReason, string}|null
     */
    private function validateShape(MeasurementRecord $record, string $batchSource): ?array
    {
        if ($record->source !== $batchSource) {
            return [RejectionReason::MalformedRow, 'The row declares a different source than the batch it arrived in.'];
        }

        if ($record->revision < 1) {
            return [RejectionReason::InvalidRevision, 'Revision numbers start at 1.'];
        }

        // A source batch cannot vouch for a manual entry's audit trail. Manual
        // correction is a separate, authenticated workflow.
        if ($record->isManual) {
            return [
                RejectionReason::ManualEntryNotSupported,
                'The row claims a manual entry, which a source import cannot record.',
            ];
        }

        // The contract makes `null` the only way to say "no reading"
        // (docs/03-data-contracts.md, section 2), so the two facts have to
        // agree in both directions. A null value under any other quality would
        // publish an observation the portal cannot show a number for, and a
        // number under `missing` would publish a reading that was never taken.
        if ($record->quality->requiresNullValue() && $record->value !== null) {
            return [
                RejectionReason::MissingRequiresNullValue,
                "Quality 'missing' cannot be reported together with a value.",
            ];
        }

        if ($record->value === null && ! $record->quality->requiresNullValue()) {
            return [
                RejectionReason::NullValueRequiresMissingQuality,
                "A row with no value must be reported as quality 'missing', not '".$record->quality->value."'.",
            ];
        }

        return null;
    }

    /**
     * @return array{state: 'created'|'updated'|'unchanged', revision_written: bool}|RejectedRow
     */
    private function persist(MeasurementRecord $record, int $stationId, int $parameterId): array|RejectedRow
    {
        return DB::transaction(function () use ($record, $stationId, $parameterId): array|RejectedRow {
            $existing = Measurement::query()
                ->where('source', $record->source)
                ->where('station_id', $stationId)
                ->where('parameter_id', $parameterId)
                // Formatted here, not passed as a Carbon: query bindings are
                // rendered by the grammar at whole-second precision, which
                // would match a neighbouring observation or nothing at all.
                ->where('observed_at', Measurement::formatTimestamp($record->observedAt))
                ->where(function ($query) use ($record): void {
                    $record->sensorNo === null
                        ? $query->whereNull('sensor_no')
                        : $query->where('sensor_no', $record->sensorNo);
                })
                // Locked so a concurrent import of the same observation cannot
                // interleave read and write and lose a revision.
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                Measurement::query()->create([
                    'source' => $record->source,
                    'source_measurement_id' => $record->sourceMeasurementId,
                    'station_id' => $stationId,
                    'parameter_id' => $parameterId,
                    'sensor_no' => $record->sensorNo,
                    'observed_at' => $record->observedAt,
                    'received_at' => $record->receivedAt,
                    // Written once. Everything after this is a revision.
                    'original_value' => $record->value,
                    'original_quality' => $record->quality,
                    'value' => $record->value,
                    'unit' => $record->unit,
                    'averaging_period' => $record->averagingPeriod,
                    'quality' => $record->quality,
                    'quality_flags' => $record->qualityFlags,
                    'revision' => $record->revision,
                    'is_manual' => false,
                    'source_updated_at' => $record->sourceUpdatedAt,
                ]);

                return ['state' => 'created', 'revision_written' => false];
            }

            if ($record->revision < $existing->revision) {
                return RejectedRow::make(
                    $record->identity(),
                    RejectionReason::StaleRevision,
                    'Revision '.$record->revision.' is older than the stored revision '.$existing->revision.'.',
                );
            }

            $valueChanged = ! $this->sameValue($existing->value, $record->value);
            $qualityChanged = $existing->quality !== $record->quality;

            if ($record->revision === $existing->revision) {
                if ($valueChanged || $qualityChanged) {
                    return RejectedRow::make(
                        $record->identity(),
                        RejectionReason::RevisionConflict,
                        'Revision '.$record->revision.' is already stored with a different value or quality.',
                    );
                }

                // Same revision, same reading: the stored row stays as it is,
                // so re-importing a batch is exactly a no-op.
                return ['state' => 'unchanged', 'revision_written' => false];
            }

            $revisionWritten = false;

            if ($valueChanged || $qualityChanged) {
                MeasurementRevision::query()->create([
                    'measurement_id' => $existing->id,
                    'revision' => $record->revision,
                    'previous_value' => $existing->value,
                    'previous_quality' => $existing->quality,
                    'corrected_value' => $record->value,
                    'corrected_quality' => $record->quality,
                    'reason_code' => MeasurementRevision::REASON_SOURCE_REVISION,
                    // The provider states no reason, and the portal does not
                    // invent one.
                    'reason_text' => null,
                    'change_origin' => RevisionOrigin::Source,
                    'changed_by' => null,
                    'source_updated_at' => $record->sourceUpdatedAt,
                ]);

                $revisionWritten = true;
            }

            $existing->fill([
                'source_measurement_id' => $record->sourceMeasurementId,
                'received_at' => $record->receivedAt,
                'value' => $record->value,
                'unit' => $record->unit,
                'averaging_period' => $record->averagingPeriod,
                'quality' => $record->quality,
                'quality_flags' => $record->qualityFlags,
                'revision' => $record->revision,
                'source_updated_at' => $record->sourceUpdatedAt,
            ]);

            $existing->save();

            return ['state' => 'updated', 'revision_written' => $revisionWritten];
        });
    }

    /**
     * Compare a stored decimal against an incoming float at the column's
     * precision, so 23.4 read back as "23.400000" is not mistaken for a change.
     */
    private function sameValue(?string $stored, ?float $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        return $stored === number_format($incoming, 6, '.', '');
    }

    /**
     * @return array<string, Station>
     */
    private function stationsFor(MeasurementBatch $batch): array
    {
        $externalIds = array_values(array_unique(
            array_map(static fn (MeasurementRecord $record): string => $record->stationExternalId, $batch->records),
        ));

        if ($externalIds === []) {
            return [];
        }

        return Station::query()
            ->where('source', $batch->source)
            ->whereIn('external_id', $externalIds)
            ->get()
            ->keyBy('external_id')
            ->all();
    }

    /**
     * @return array<string, Parameter>
     */
    private function parametersFor(MeasurementBatch $batch): array
    {
        $codes = array_values(array_unique(
            array_map(static fn (MeasurementRecord $record): string => $record->parameterCode, $batch->records),
        ));

        if ($codes === []) {
            return [];
        }

        return Parameter::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code')
            ->all();
    }
}

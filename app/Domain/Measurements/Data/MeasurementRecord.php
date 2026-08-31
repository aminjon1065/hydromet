<?php

namespace App\Domain\Measurements\Data;

use App\Domain\Measurements\Enums\MeasurementQuality;
use App\Support\Canonical\CanonicalReader;
use App\Support\Canonical\InvalidCanonicalRow;
use Illuminate\Support\Carbon;

/**
 * Canonical measurement, docs/03-data-contracts.md section 5.1.
 *
 * The only measurement shape the portal's business code knows. Adapters
 * translate a provider payload into it; nothing downstream reads a provider
 * field name.
 *
 * A record being constructible means it is structurally readable, not that it
 * is acceptable: station and parameter resolution, unit agreement and revision
 * ordering are decided by the import service, which owns the tables.
 */
final readonly class MeasurementRecord
{
    /**
     * UTC instant with exactly six fractional digits. The record is always
     * normalized to UTC by the reader, so the literal `Z` is accurate.
     */
    private const ISO_MICROSECOND_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @param  list<string>  $qualityFlags
     */
    public function __construct(
        public string $source,
        public ?string $sourceMeasurementId,
        public string $stationExternalId,
        public string $parameterCode,
        public ?string $sensorNo,
        public Carbon $observedAt,
        public ?Carbon $receivedAt,
        public ?float $value,
        public string $unit,
        public ?string $averagingPeriod,
        public MeasurementQuality $quality,
        public array $qualityFlags,
        public int $revision,
        public bool $isManual,
        public ?Carbon $sourceUpdatedAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidCanonicalRow
     */
    public static function fromCanonical(array $row): self
    {
        $reader = new CanonicalReader($row);

        return new self(
            source: $reader->string('source'),
            sourceMeasurementId: $reader->nullableString('source_measurement_id'),
            stationExternalId: $reader->string('station_external_id'),
            parameterCode: $reader->string('parameter_code'),
            sensorNo: $reader->nullableString('sensor_no'),
            observedAt: $reader->dateTime('observed_at'),
            receivedAt: $reader->nullableDateTime('received_at'),
            // Required key, nullable value: `null` is the canonical way to say
            // "no reading", so an absent key is a malformed row rather than a
            // missing observation.
            value: $reader->requiredNullableFloat('value'),
            unit: $reader->string('unit'),
            averagingPeriod: $reader->nullableString('averaging_period'),
            quality: $reader->enum('quality', MeasurementQuality::class),
            qualityFlags: $reader->stringList('quality_flags'),
            revision: $reader->integer('revision'),
            isManual: $reader->boolean('is_manual'),
            sourceUpdatedAt: $reader->nullableDateTime('source_updated_at'),
        );
    }

    /**
     * Safe reference for rejection reporting. Built from the natural key, so an
     * operator can find the row in the provider's own export.
     */
    public function identity(): string
    {
        return implode(':', [
            $this->source,
            $this->stationExternalId,
            $this->parameterCode,
            $this->observedAtIso(),
            $this->sensorNo ?? '-',
        ]);
    }

    /**
     * Natural-key fingerprint used to spot two rows claiming the same
     * observation inside one batch, before the database is asked.
     */
    public function naturalKey(int $stationId, int $parameterId): string
    {
        return implode('|', [
            $this->source,
            (string) $stationId,
            (string) $parameterId,
            $this->observedAtIso(),
            $this->sensorNo ?? '',
        ]);
    }

    /**
     * The observation time with all six fractional digits.
     *
     * Written out rather than taken from Carbon's default ISO helper, which
     * renders whole seconds: two readings a tenth of a second apart are
     * different observations, and both the batch duplicate check and the
     * rejection reference have to be able to tell them apart.
     */
    public function observedAtIso(): string
    {
        return $this->observedAt->format(self::ISO_MICROSECOND_FORMAT);
    }
}

<?php

namespace App\Domain\Stations\Data;

use App\Domain\Stations\Enums\StationStatus;
use App\Domain\Stations\Enums\StationType;
use App\Domain\Stations\Exceptions\InvalidCanonicalRow;
use Illuminate\Support\Carbon;

/**
 * Canonical station registry record, docs/03-data-contracts.md section 3.1.
 *
 * This is the only station shape the portal's business code knows. Adapters
 * translate a provider payload into it; nothing downstream may read a provider
 * field name.
 *
 * A record being constructible means it is structurally readable, not that it
 * is acceptable: coordinate ranges, timezone identifiers and parameter codes
 * are checked by the import service, which owns the stations tables.
 */
final readonly class StationRecord
{
    /**
     * @param  list<string>  $parameterCodes
     */
    public function __construct(
        public string $source,
        public string $externalId,
        public string $code,
        public string $nameTj,
        public string $nameRu,
        public string $nameEn,
        public float $latitude,
        public float $longitude,
        public ?float $elevationM,
        public string $regionCode,
        public ?string $districtCode,
        public string $timezone,
        public StationStatus $status,
        public StationType $stationType,
        public ?string $owner,
        public ?Carbon $installedAt,
        public Carbon $sourceUpdatedAt,
        public array $parameterCodes,
    ) {}

    /**
     * Read one canonical row.
     *
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidCanonicalRow
     */
    public static function fromCanonical(array $row): self
    {
        $reader = new CanonicalReader($row);
        $name = $reader->localized('name');

        return new self(
            source: $reader->string('source'),
            externalId: $reader->string('external_id'),
            code: $reader->string('code'),
            nameTj: $name['tj'],
            nameRu: $name['ru'],
            nameEn: $name['en'],
            latitude: $reader->float('latitude'),
            longitude: $reader->float('longitude'),
            elevationM: $reader->nullableFloat('elevation_m'),
            regionCode: $reader->string('region_code'),
            districtCode: $reader->nullableString('district_code'),
            timezone: $reader->string('timezone'),
            status: $reader->enum('status', StationStatus::class),
            stationType: $reader->enum('station_type', StationType::class),
            owner: $reader->nullableString('owner'),
            installedAt: $reader->nullableDate('installed_at'),
            // The provider's own record revision time, not the portal's.
            sourceUpdatedAt: $reader->dateTime('updated_at'),
            parameterCodes: $reader->stringList('parameters'),
        );
    }

    /**
     * Stable identity used for upserts: provider key plus provider ID.
     */
    public function identity(): string
    {
        return $this->source.':'.$this->externalId;
    }

    /**
     * Attributes written to the stations table.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'code' => $this->code,
            'name_tj' => $this->nameTj,
            'name_ru' => $this->nameRu,
            'name_en' => $this->nameEn,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'elevation_m' => $this->elevationM,
            'region_code' => $this->regionCode,
            'district_code' => $this->districtCode,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'station_type' => $this->stationType,
            'owner' => $this->owner,
            'installed_at' => $this->installedAt,
            'source_updated_at' => $this->sourceUpdatedAt,
        ];
    }
}

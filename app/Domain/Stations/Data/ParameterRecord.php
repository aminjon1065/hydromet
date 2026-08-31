<?php

namespace App\Domain\Stations\Data;

use App\Domain\Stations\Enums\ParameterKind;
use App\Domain\Stations\Exceptions\InvalidCanonicalRow;

/**
 * Canonical parameter catalogue entry, docs/03-data-contracts.md section 4.
 *
 * Unit, precision and averaging period are always supplied by the provider.
 * The portal never derives them from the code.
 */
final readonly class ParameterRecord
{
    public function __construct(
        public string $code,
        public ParameterKind $kind,
        public string $nameTj,
        public string $nameRu,
        public string $nameEn,
        public string $canonicalUnit,
        public int $precision,
        public ?string $defaultAveragingPeriod,
        public ?float $plausibleMin,
        public ?float $plausibleMax,
        public bool $active,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidCanonicalRow
     */
    public static function fromCanonical(array $row): self
    {
        $reader = new CanonicalReader($row);
        $name = $reader->localized('name');

        return new self(
            code: $reader->string('code'),
            kind: $reader->enum('kind', ParameterKind::class),
            nameTj: $name['tj'],
            nameRu: $name['ru'],
            nameEn: $name['en'],
            canonicalUnit: $reader->string('canonical_unit'),
            precision: $reader->integer('precision'),
            defaultAveragingPeriod: $reader->nullableString('default_averaging_period'),
            plausibleMin: $reader->nullableFloat('plausible_min'),
            plausibleMax: $reader->nullableFloat('plausible_max'),
            active: $reader->boolean('active'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'kind' => $this->kind,
            'name_tj' => $this->nameTj,
            'name_ru' => $this->nameRu,
            'name_en' => $this->nameEn,
            'canonical_unit' => $this->canonicalUnit,
            'precision' => $this->precision,
            'default_averaging_period' => $this->defaultAveragingPeriod,
            'plausible_min' => $this->plausibleMin,
            'plausible_max' => $this->plausibleMax,
            'active' => $this->active,
        ];
    }
}

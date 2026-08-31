<?php

namespace App\Domain\Integrations\Fixtures;

/**
 * Which synthetic measurement batch to read.
 *
 * A closed set rather than a free-text path: the operator picks a named
 * scenario, and no argument can point the importer at an arbitrary file.
 */
enum FixtureMeasurementScenario: string
{
    /** The historical batch every other scenario builds on. */
    case Base = 'base';

    /** One observation from the base batch, restated at a higher revision. */
    case Correction = 'correction';

    public function fileName(): string
    {
        return match ($this) {
            self::Base => 'measurements-base.fixture.json',
            self::Correction => 'measurements-correction.fixture.json',
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Base => 'base historical batch',
            self::Correction => 'source correction batch',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

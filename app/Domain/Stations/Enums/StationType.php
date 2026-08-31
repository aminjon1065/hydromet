<?php

namespace App\Domain\Stations\Enums;

/**
 * Station type, docs/03-data-contracts.md section 3.1.
 */
enum StationType: string
{
    case AirQuality = 'air_quality';
    case Meteorological = 'meteorological';
    case Combined = 'combined';

    public function label(): string
    {
        return __('stations.types.'.$this->value);
    }
}

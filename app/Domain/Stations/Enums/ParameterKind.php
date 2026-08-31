<?php

namespace App\Domain\Stations\Enums;

/**
 * Parameter classification, docs/03-data-contracts.md section 4.
 */
enum ParameterKind: string
{
    case Pollutant = 'pollutant';
    case Meteorological = 'meteorological';
    case Derived = 'derived';

    public function label(): string
    {
        return __('stations.parameter_kinds.'.$this->value);
    }
}

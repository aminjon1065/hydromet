<?php

namespace App\Domain\Stations\Enums;

/**
 * Station lifecycle status, docs/03-data-contracts.md section 3.1.
 */
enum StationStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Offline = 'offline';
    case Decommissioned = 'decommissioned';

    public function label(): string
    {
        return __('stations.statuses.'.$this->value);
    }
}

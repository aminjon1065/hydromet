<?php

namespace App\Domain\Measurements\Enums;

/**
 * Who changed a measurement.
 *
 * Only `Source` is produced in this phase. `Manual` exists so the manual
 * correction workflow (docs/03-data-contracts.md, section 5.3) can be added
 * without altering the revision table, and so a reader never has to guess
 * whether an untagged revision came from a provider or a person.
 */
enum RevisionOrigin: string
{
    case Source = 'source';
    case Manual = 'manual';

    public function label(): string
    {
        return __('measurements.revision_origins.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

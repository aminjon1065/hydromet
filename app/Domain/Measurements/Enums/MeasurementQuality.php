<?php

namespace App\Domain\Measurements\Enums;

/**
 * Quality of a stored observation, docs/03-data-contracts.md section 5.1.
 *
 * `Missing` is the portal's only representation of "no reading": it is always
 * paired with a null value, never with zero or a sentinel number
 * (docs/03-data-contracts.md, section 2).
 *
 * `Corrected` describes a value the source itself revised. It is not a manual
 * correction — that workflow, and the roles that may perform it, arrive later.
 */
enum MeasurementQuality: string
{
    case Valid = 'valid';
    case Suspect = 'suspect';
    case Invalid = 'invalid';
    case Missing = 'missing';
    case Corrected = 'corrected';

    public function label(): string
    {
        return __('measurements.qualities.'.$this->value);
    }

    /**
     * Whether this quality forbids a value being present.
     */
    public function requiresNullValue(): bool
    {
        return $this === self::Missing;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

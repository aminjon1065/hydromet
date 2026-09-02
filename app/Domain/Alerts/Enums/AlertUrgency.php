<?php

namespace App\Domain\Alerts\Enums;

/**
 * CAP 1.2 `info.urgency`, docs/03-data-contracts.md section 7.
 */
enum AlertUrgency: string
{
    case Immediate = 'Immediate';
    case Expected = 'Expected';
    case Future = 'Future';
    case Past = 'Past';
    case Unknown = 'Unknown';

    public function label(): string
    {
        return __('alerts.urgencies.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

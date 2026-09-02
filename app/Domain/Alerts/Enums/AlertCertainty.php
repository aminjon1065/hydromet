<?php

namespace App\Domain\Alerts\Enums;

/**
 * CAP 1.2 `info.certainty`, docs/03-data-contracts.md section 7.
 */
enum AlertCertainty: string
{
    case Observed = 'Observed';
    case Likely = 'Likely';
    case Possible = 'Possible';
    case Unlikely = 'Unlikely';
    case Unknown = 'Unknown';

    public function label(): string
    {
        return __('alerts.certainties.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Domain\Integrations\Enums;

/**
 * What a synchronization run was importing.
 *
 * One case per import service that exists. `StationRegistry` covers the
 * parameter catalogue too: they arrive in one provider read and are stored in
 * one pass, so splitting them would journal two runs for one operator action.
 */
enum SynchronizationKind: string
{
    case StationRegistry = 'station_registry';
    case Measurements = 'measurements';

    public function label(): string
    {
        return __('integrations.kinds.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Filament\Resources\Stations\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\Stations\StationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStation extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = StationResource::class;

    /**
     * No edit action: imported reference data is read-only in the panel.
     *
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

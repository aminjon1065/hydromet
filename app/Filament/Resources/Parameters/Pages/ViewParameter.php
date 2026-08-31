<?php

namespace App\Filament\Resources\Parameters\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\Parameters\ParameterResource;
use Filament\Resources\Pages\ViewRecord;

class ViewParameter extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = ParameterResource::class;

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

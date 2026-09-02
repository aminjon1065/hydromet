<?php

namespace App\Filament\Resources\AlertMessages\Pages;

use App\Filament\Concerns\ResolvesNumericRecordKey;
use App\Filament\Resources\AlertMessages\AlertMessageResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAlertMessage extends ViewRecord
{
    use ResolvesNumericRecordKey;

    protected static string $resource = AlertMessageResource::class;

    /**
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

<?php

namespace App\Filament\Resources\AlertMessages\Pages;

use App\Filament\Resources\AlertMessages\AlertMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListAlertMessages extends ListRecords
{
    protected static string $resource = AlertMessageResource::class;

    /**
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
